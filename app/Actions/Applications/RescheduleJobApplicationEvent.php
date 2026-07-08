<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationScheduledEvent;
use App\Models\JobApplicationScheduledEventHistory;
use App\Models\JobApplicationScheduledEventReplacement;
use App\Models\User;
use App\Services\Applications\JobApplicationScheduledEventPayloadBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RescheduleJobApplicationEvent
{
    private const TERMINAL_APPLICATION_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    public function __construct(
        private readonly JobApplicationScheduledEventPayloadBuilder $payloadBuilder,
    ) {
    }

    public function execute(
        JobApplicationScheduledEvent $scheduledEvent,
        User $actor,
        array $input,
    ): JobApplicationScheduledEventReplacement {
        return DB::transaction(function () use ($scheduledEvent, $actor, $input): JobApplicationScheduledEventReplacement {
            $eventReference = JobApplicationScheduledEvent::query()
                ->select(['id', 'job_application_id'])
                ->findOrFail($scheduledEvent->getKey());
            $application = JobApplication::query()
                ->with('profile')
                ->lockForUpdate()
                ->findOrFail($eventReference->job_application_id);
            $scheduledEvent = JobApplicationScheduledEvent::query()
                ->where('job_application_id', $application->getKey())
                ->lockForUpdate()
                ->findOrFail($eventReference->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this scheduled event.');
            }

            $reschedule = $this->validatedReschedule($input);
            $attributes = $this->payloadBuilder->build(
                $reschedule['replacement_event'],
                $actor,
                false,
            );
            $hasChangedAt = array_key_exists('changed_at', $reschedule)
                && $reschedule['changed_at'] !== null;
            $changedAt = $hasChangedAt
                ? CarbonImmutable::parse($reschedule['changed_at'])
                : CarbonImmutable::now();
            $notes = $this->nullableTrimmed($reschedule['notes'] ?? null);

            $existingByReference = JobApplicationScheduledEventReplacement::query()
                ->with('replacementEvent')
                ->where('job_application_id', $application->getKey())
                ->where('client_reference', $reschedule['client_reference'])
                ->lockForUpdate()
                ->first();

            if ($existingByReference !== null) {
                if (! $this->sameReplacement(
                    $existingByReference,
                    $scheduledEvent,
                    $actor,
                    $attributes,
                    $changedAt,
                    $hasChangedAt,
                    $notes,
                )) {
                    throw ValidationException::withMessages([
                        'client_reference' => 'The client reference is already associated with another event reschedule payload.',
                    ]);
                }

                return $existingByReference->load($this->relations());
            }

            $existingForEvent = JobApplicationScheduledEventReplacement::query()
                ->where('previous_scheduled_event_id', $scheduledEvent->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingForEvent !== null) {
                throw ValidationException::withMessages([
                    'scheduled_event' => 'The scheduled event has already been replaced.',
                ]);
            }

            if (in_array($application->status, self::TERMINAL_APPLICATION_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'job_application' => 'A terminal application cannot receive a replacement scheduled event.',
                ]);
            }

            if ($scheduledEvent->status !== 'planned') {
                throw ValidationException::withMessages([
                    'scheduled_event' => 'Only a planned scheduled event can be rescheduled.',
                ]);
            }

            if ($changedAt->isFuture()) {
                throw ValidationException::withMessages([
                    'changed_at' => 'An event reschedule cannot be recorded in the future.',
                ]);
            }

            $latestHistory = JobApplicationScheduledEventHistory::query()
                ->where('scheduled_event_id', $scheduledEvent->getKey())
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latestHistory !== null && $changedAt->lt($latestHistory->changed_at)) {
                throw ValidationException::withMessages([
                    'changed_at' => 'The reschedule date cannot precede the latest scheduled event history entry.',
                ]);
            }

            $this->payloadBuilder->ensureFuture($attributes);
            $this->ensureReplacementClientReferenceIsAvailable(
                $application,
                $attributes['client_reference'],
            );

            $scheduledEvent->forceFill([
                'status' => 'cancelled',
                'resolved_by' => $actor->getKey(),
                'resolved_at' => $changedAt,
                'resolution_notes' => $notes,
            ])->save();
            $scheduledEvent->statusHistory()->create([
                'job_application_id' => $application->getKey(),
                'changed_by' => $actor->getKey(),
                'from_status' => 'planned',
                'status' => 'cancelled',
                'changed_at' => $changedAt,
                'notes' => $notes,
            ]);

            $replacementEvent = $application->scheduledEvents()->create($attributes);
            $replacementEvent->statusHistory()->create([
                'job_application_id' => $application->getKey(),
                'changed_by' => $actor->getKey(),
                'from_status' => null,
                'status' => 'planned',
                'changed_at' => $changedAt,
                'notes' => $notes,
            ]);

            $replacement = JobApplicationScheduledEventReplacement::create([
                'job_application_id' => $application->getKey(),
                'previous_scheduled_event_id' => $scheduledEvent->getKey(),
                'replacement_scheduled_event_id' => $replacementEvent->getKey(),
                'changed_by' => $actor->getKey(),
                'client_reference' => $reschedule['client_reference'],
                'changed_at' => $changedAt,
                'notes' => $notes,
            ]);

            return $replacement->load($this->relations());
        });
    }

    private function validatedReschedule(array $input): array
    {
        $input = $this->normalizeInput($input);

        return Validator::make(['reschedule' => $input], [
            'reschedule' => [
                'required',
                'array:client_reference,changed_at,notes,replacement_event',
            ],
            'reschedule.client_reference' => ['required', 'string', 'max:100'],
            'reschedule.changed_at' => ['nullable', 'date'],
            'reschedule.notes' => ['nullable', 'string', 'max:5000'],
            'reschedule.replacement_event' => ['required', 'array'],
        ])->validate()['reschedule'];
    }

    private function normalizeInput(array $input): array
    {
        if (array_key_exists('client_reference', $input) && is_string($input['client_reference'])) {
            $input['client_reference'] = $this->nullableSquished($input['client_reference']);
        }

        if (array_key_exists('changed_at', $input) && is_string($input['changed_at'])) {
            $input['changed_at'] = trim($input['changed_at']);
        }

        if (array_key_exists('notes', $input) && is_string($input['notes'])) {
            $input['notes'] = $this->nullableTrimmed($input['notes']);
        }

        return $input;
    }

    private function sameReplacement(
        JobApplicationScheduledEventReplacement $existing,
        JobApplicationScheduledEvent $scheduledEvent,
        User $actor,
        array $attributes,
        CarbonImmutable $changedAt,
        bool $compareChangedAt,
        ?string $notes,
    ): bool {
        if (
            (int) $existing->previous_scheduled_event_id !== (int) $scheduledEvent->getKey()
            || (int) $existing->changed_by !== (int) $actor->getKey()
            || $existing->notes !== $notes
            || $existing->replacementEvent === null
        ) {
            return false;
        }

        if (
            $compareChangedAt
            && $existing->changed_at->getTimestamp() !== $changedAt->getTimestamp()
        ) {
            return false;
        }

        return $this->payloadBuilder->same(
            $existing->replacementEvent,
            $attributes,
        );
    }

    private function ensureReplacementClientReferenceIsAvailable(
        JobApplication $application,
        ?string $clientReference,
    ): void {
        if ($clientReference === null) {
            return;
        }

        $existing = JobApplicationScheduledEvent::query()
            ->where('job_application_id', $application->getKey())
            ->where('client_reference', $clientReference)
            ->lockForUpdate()
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'replacement_event.client_reference' => 'The replacement event client reference is already in use for this application.',
            ]);
        }
    }

    private function relations(): array
    {
        return [
            'jobApplication',
            'changedBy',
            'previousEvent.createdBy',
            'previousEvent.resolvedBy',
            'previousEvent.statusHistory.changedBy',
            'replacementEvent.createdBy',
            'replacementEvent.resolvedBy',
            'replacementEvent.statusHistory.changedBy',
        ];
    }

    private function nullableSquished(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::squish($value);

        return $value === '' ? null : $value;
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
