<?php

namespace App\Services\Applications;

use App\Models\JobApplicationScheduledEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobApplicationScheduledEventPayloadBuilder
{
    public function build(
        array $input,
        User $actor,
        bool $requireFuture = true,
    ): array {
        $event = $this->validatedEvent($input);
        $attributes = [
            'created_by' => $actor->getKey(),
            'client_reference' => $event['client_reference'] ?? null,
            'event_type' => $event['event_type'],
            'title' => $event['title'],
            'starts_at' => CarbonImmutable::parse($event['starts_at']),
            'ends_at' => isset($event['ends_at']) && $event['ends_at'] !== null
                ? CarbonImmutable::parse($event['ends_at'])
                : null,
            'location' => $event['location'] ?? null,
            'meeting_url' => $event['meeting_url'] ?? null,
            'contact_name' => $event['contact_name'] ?? null,
            'contact_email' => $event['contact_email'] ?? null,
            'notes' => $event['notes'] ?? null,
            'status' => 'planned',
        ];

        $this->ensureChronology($attributes);

        if ($requireFuture) {
            $this->ensureFuture($attributes);
        }

        return $attributes;
    }

    public function ensureFuture(array $attributes): void
    {
        if (! $attributes['starts_at']->isFuture()) {
            throw ValidationException::withMessages([
                'starts_at' => 'A scheduled event must start in the future.',
            ]);
        }
    }

    public function same(
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

    private function ensureChronology(array $attributes): void
    {
        if (
            $attributes['ends_at'] !== null
            && ! $attributes['ends_at']->gt($attributes['starts_at'])
        ) {
            throw ValidationException::withMessages([
                'ends_at' => 'The event end must be after its start.',
            ]);
        }
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
