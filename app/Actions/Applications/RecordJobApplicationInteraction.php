<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationInteraction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordJobApplicationInteraction
{
    public function execute(
        JobApplication $application,
        User $actor,
        array $input,
    ): JobApplicationInteraction {
        return DB::transaction(function () use ($application, $actor, $input): JobApplicationInteraction {
            $application = JobApplication::query()
                ->with('profile')
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            $interaction = $this->validatedInteraction($input);
            $occurredAt = CarbonImmutable::parse($interaction['occurred_at']);

            if ($occurredAt->isFuture()) {
                throw ValidationException::withMessages([
                    'occurred_at' => 'An interaction cannot be recorded in the future.',
                ]);
            }

            $attributes = [
                'recorded_by' => $actor->getKey(),
                'client_reference' => $interaction['client_reference'] ?? null,
                'interaction_type' => $interaction['interaction_type'],
                'direction' => $interaction['direction'],
                'subject' => $interaction['subject'] ?? null,
                'contact_name' => $interaction['contact_name'] ?? null,
                'contact_email' => $interaction['contact_email'] ?? null,
                'occurred_at' => $occurredAt,
                'notes' => $interaction['notes'] ?? null,
            ];

            if ($attributes['subject'] === null && $attributes['notes'] === null) {
                throw ValidationException::withMessages([
                    'interaction' => 'An interaction requires a subject or notes.',
                ]);
            }

            if ($attributes['client_reference'] !== null) {
                $existing = JobApplicationInteraction::query()
                    ->where('job_application_id', $application->getKey())
                    ->where('client_reference', $attributes['client_reference'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! $this->sameInteraction($existing, $attributes)) {
                        throw ValidationException::withMessages([
                            'client_reference' => 'The client reference is already associated with another interaction payload.',
                        ]);
                    }

                    return $existing->load('recordedBy');
                }
            }

            return $application->interactions()
                ->create($attributes)
                ->load('recordedBy');
        });
    }

    private function validatedInteraction(array $input): array
    {
        $input = $this->normalizeInput($input);

        return Validator::make(['interaction' => $input], [
            'interaction' => [
                'required',
                'array:client_reference,interaction_type,direction,subject,contact_name,contact_email,occurred_at,notes',
            ],
            'interaction.client_reference' => ['nullable', 'string', 'max:100'],
            'interaction.interaction_type' => [
                'required',
                'string',
                Rule::in(JobApplicationInteraction::TYPES),
            ],
            'interaction.direction' => [
                'required',
                'string',
                Rule::in(JobApplicationInteraction::DIRECTIONS),
            ],
            'interaction.subject' => ['nullable', 'string', 'max:255'],
            'interaction.contact_name' => ['nullable', 'string', 'max:255'],
            'interaction.contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'interaction.occurred_at' => ['required', 'date'],
            'interaction.notes' => ['nullable', 'string', 'max:5000'],
        ])->validate()['interaction'];
    }

    private function normalizeInput(array $input): array
    {
        foreach (['client_reference', 'interaction_type', 'direction', 'subject', 'contact_name'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = $this->nullableSquished($input[$field]);
            }
        }

        if (array_key_exists('contact_email', $input) && is_string($input['contact_email'])) {
            $input['contact_email'] = $this->nullableEmail($input['contact_email']);
        }

        if (array_key_exists('occurred_at', $input) && is_string($input['occurred_at'])) {
            $input['occurred_at'] = trim($input['occurred_at']);
        }

        if (array_key_exists('notes', $input) && is_string($input['notes'])) {
            $input['notes'] = $this->nullableTrimmed($input['notes']);
        }

        return $input;
    }

    private function sameInteraction(
        JobApplicationInteraction $existing,
        array $attributes,
    ): bool {
        return (int) $existing->recorded_by === (int) $attributes['recorded_by']
            && $existing->interaction_type === $attributes['interaction_type']
            && $existing->direction === $attributes['direction']
            && $existing->subject === $attributes['subject']
            && $existing->contact_name === $attributes['contact_name']
            && $existing->contact_email === $attributes['contact_email']
            && $existing->occurred_at->getTimestamp() === $attributes['occurred_at']->getTimestamp()
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
