<?php

namespace App\Actions\Applications;

use App\Models\JobApplicationScheduledEvent;
use App\Models\JobApplicationScheduledEventHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResolveJobApplicationScheduledEvent
{
    private const TARGET_STATUSES = [
        'completed',
        'cancelled',
    ];

    public function execute(
        JobApplicationScheduledEvent $scheduledEvent,
        User $actor,
        array $input,
    ): JobApplicationScheduledEvent {
        return DB::transaction(function () use ($scheduledEvent, $actor, $input): JobApplicationScheduledEvent {
            $scheduledEvent = JobApplicationScheduledEvent::query()
                ->with('jobApplication.profile')
                ->lockForUpdate()
                ->findOrFail($scheduledEvent->getKey());

            if ((int) $scheduledEvent->jobApplication->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this scheduled event.');
            }

            $resolution = $this->validatedResolution($input);
            $targetStatus = $resolution['status'];

            if ($scheduledEvent->status === $targetStatus) {
                return $scheduledEvent->fresh($this->relations());
            }

            if ($scheduledEvent->status !== 'planned') {
                throw ValidationException::withMessages([
                    'status' => 'Only a planned scheduled event can be resolved.',
                ]);
            }

            $changedAt = isset($resolution['changed_at'])
                ? CarbonImmutable::parse($resolution['changed_at'])
                : CarbonImmutable::now();

            if ($changedAt->isFuture()) {
                throw ValidationException::withMessages([
                    'changed_at' => 'A scheduled event resolution cannot be recorded in the future.',
                ]);
            }

            if (
                $targetStatus === 'completed'
                && $changedAt->lt($scheduledEvent->starts_at)
            ) {
                throw ValidationException::withMessages([
                    'changed_at' => 'A scheduled event cannot be completed before it starts.',
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
                    'changed_at' => 'The resolution date cannot precede the latest scheduled event history entry.',
                ]);
            }

            $notes = $this->nullableTrimmed($resolution['notes'] ?? null);
            $scheduledEvent->forceFill([
                'status' => $targetStatus,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => $changedAt,
                'resolution_notes' => $notes,
            ])->save();
            $scheduledEvent->statusHistory()->create([
                'job_application_id' => $scheduledEvent->job_application_id,
                'changed_by' => $actor->getKey(),
                'from_status' => 'planned',
                'status' => $targetStatus,
                'changed_at' => $changedAt,
                'notes' => $notes,
            ]);

            return $scheduledEvent->fresh($this->relations());
        });
    }

    private function validatedResolution(array $input): array
    {
        return Validator::make(['resolution' => $input], [
            'resolution' => ['required', 'array:status,changed_at,notes'],
            'resolution.status' => [
                'required',
                'string',
                Rule::in(self::TARGET_STATUSES),
            ],
            'resolution.changed_at' => ['nullable', 'date'],
            'resolution.notes' => ['nullable', 'string', 'max:5000'],
        ])->validate()['resolution'];
    }

    private function relations(): array
    {
        return [
            'jobApplication',
            'createdBy',
            'resolvedBy',
            'statusHistory.changedBy',
        ];
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
