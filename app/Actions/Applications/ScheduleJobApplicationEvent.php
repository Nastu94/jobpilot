<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationScheduledEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleJobApplicationEvent
{
    private const TERMINAL_APPLICATION_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    public function execute(
        JobApplication $application,
        User $actor,
        array $input,
    ): JobApplicationScheduledEvent {
        return DB::transaction(function () use ($application, $actor, $input): JobApplicationScheduledEvent {
            $application = JobApplication::query()
                ->with('profile')
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            if (in_array($application->status, self::TERMINAL_APPLICATION_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'job_application' => 'A terminal application cannot receive a new scheduled event.',
                ]);
            }

            $event = $this->validatedEvent($input);
            $startsAt = CarbonImmutable::parse($event['starts_at']);
            $endsAt = isset($event['ends_at']) && $event['ends_at'] !== null
                ? CarbonImmutable::parse($event['ends_at'])
                : null;

            if (! $startsAt->isFuture()) {
                throw ValidationException::withMessages([
                    'starts_at' => 'A scheduled event must start in the future.',
                ]);
            }

            if ($endsAt !== null && ! $endsAt->gt($startsAt)) {
                throw ValidationException::withMessages([
                    'ends_at' => 'The event end must be after its start.',
                ]);
            }

            $attributes = [
                'created_by' => $actor->getKey(),
                'client_reference' => $event['client_reference'] ?? null,
                'event_type' => $event['event_type'],
                'title' => $event['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'location' => $event['location'] ?? null,
                'meeting_url' => $event['meeting_url'] ?? null,
                'contact_name' => $event['contact_name'] ?? null,
                'contact_email' => $event['contact_email'] ?? null,
                'notes' => $event['notes'] ?? null,
                'status' => 'planned',
            ];

            if ($attributes['client_reference'] !== null) {
                $existing = JobApplicationScheduledEvent::query()
                    ->where('job_application_id', $application->getKey())
                    ->where('client_reference', $attributes['client_reference'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! $this->sameScheduledEvent($existing, $attributes)) {
                        throw ValidationException::withMessages([
                            'client_reference' => 'The client reference is already associated with another scheduled event payload.',
                        ]);
                    }

                    return $existing->load([
                        'createdBy',
                        'resolvedBy',
                        'statusHistory.changedBy',
                    ]);
                }
            }

            $scheduledEvent = $application->scheduledEvents()->create($attributes);
            $scheduledEvent->statusHistory()->create([
                'job_application_id' => $application->getKey(),
                'changed_by' => $actor->getKey(),
                'from_status' => null,
                'status' => 'planned',
                'changed_at' => CarbonImmutable::now(),
                'notes' => null,
            ]);

            return $scheduledEvent->load([
                'createdBy',
                'resolvedBy',
                'statusHistory.changedBy',
            ]);
        });
    }

    private function validatedEvent(array $input): array
    {
        $input = $this->normalizeInput($input);

        return Validator::make(['event' => $input], [
            'event' => [
                'required',
                'array:client_reference,event_type,title,starts_at,ends_at,location,meeting_url,contact_name,contact_email,notes',
            ],
            'event.client_reference' => ['nullable', 'string', 'max:100'],
            'event.event_type' => [
                'required',
                'string',
                Rule::in(JobApplicationScheduledEvent::TYPES),
            ],
            'event.title' => ['required', 'string', 'max:255'],
            'event.starts_at' => ['required', 'date'],
            'event.ends_at' => ['nullable', 'date'],
            'event.location' => ['nullable', 'string', 'max:255'],
            'event.meeting_url' => ['nullable', 'string', 'url', 'max:2048'],
            'event.contact_name' => ['nullable', 'string', 'max:255'],
            'event.contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'event.notes' => ['nullable', 'string', 'max:5000'],
        ])->after(function ($validator) use ($input): void {
            $meetingUrl = $input['meeting_url'] ?? null;

            if (
                is_string($meetingUrl)
                && ! str_starts_with($meetingUrl, 'https://')
                && ! str_starts_with($meetingUrl, 'http://')
            ) {
                $validator->errors()->add(
                    'event.meeting_url',
                    'The meeting URL must use HTTP or HTTPS.',
                );
            }
        })->validate()['event'];
    }

    private function normalizeInput(array $input): array
    {
        foreach (['client_reference', 'event_type', 'title', 'location', 'meeting_url', 'contact_name'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = $this->nullableSquished($input[$field]);
            }
        }

        if (array_key_exists('contact_email', $input) && is_string($input['contact_email'])) {
            $input['contact_email'] = $this->nullableEmail($input['contact_email']);
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        if (array_key_exists('notes', $input) && is_string($input['notes'])) {
            $input['notes'] = $this->nullableTrimmed($input['notes']);
        }

        return $input;
    }

    private function sameScheduledEvent(
        JobApplicationScheduledEvent $existing,
        array $attributes,
    ): bool {
        $existingEndsAt = $existing->ends_at?->getTimestamp();
        $incomingEndsAt = $attributes['ends_at'] === null
            ? null
            : $attributes['ends_at']->getTimestamp();

        return (int) $existing->created_by === (int) $attributes['created_by']
            && $existing->event_type === $attributes['event_type']
            && $existing->title === $attributes['title']
            && $existing->starts_at->getTimestamp() === $attributes['starts_at']->getTimestamp()
            && $existingEndsAt === $incomingEndsAt
            && $existing->location === $attributes['location']
            && $existing->meeting_url === $attributes['meeting_url']
            && $existing->contact_name === $attributes['contact_name']
            && $existing->contact_email === $attributes['contact_email']
            && $existing->notes === $attributes['notes'];
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

    private function nullableEmail(?string $value): ?string
    {
        $value = $this->nullableSquished($value);

        return $value === null ? null : Str::lower($value);
    }
}
