<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationSubmissionConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConfirmJobApplicationManualSubmission
{
    public function __construct(
        private readonly TransitionJobApplicationStatus $transitionStatus,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
        array $input,
    ): JobApplicationSubmissionConfirmation {
        return DB::transaction(function () use ($application, $actor, $input): JobApplicationSubmissionConfirmation {
            $application = JobApplication::query()
                ->with([
                    'profile',
                    'jobPosting',
                    'generatedDocumentVersion',
                ])
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            $confirmationInput = $this->validatedConfirmation($input);
            $submittedAt = CarbonImmutable::parse(
                $confirmationInput['submitted_at'],
            );
            $existing = JobApplicationSubmissionConfirmation::query()
                ->where('job_application_id', $application->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $this->sameConfirmation(
                    $existing,
                    $actor,
                    $confirmationInput,
                    $submittedAt,
                )) {
                    throw ValidationException::withMessages([
                        'submission_confirmation' => 'The application already has a different manual submission confirmation.',
                    ]);
                }

                return $existing->load($this->relations());
            }

            if ($application->status !== 'draft') {
                throw ValidationException::withMessages([
                    'job_application' => 'Only a draft application can receive its first manual submission confirmation.',
                ]);
            }

            if ($submittedAt->isFuture()) {
                throw ValidationException::withMessages([
                    'submitted_at' => 'A manual submission confirmation cannot be recorded in the future.',
                ]);
            }

            if (
                $application->created_at !== null
                && $submittedAt->lt($application->created_at)
            ) {
                throw ValidationException::withMessages([
                    'submitted_at' => 'The submission date cannot precede the application creation date.',
                ]);
            }

            $application = $this->transitionStatus->execute(
                $application,
                $actor,
                [
                    'status' => 'applied',
                    'changed_at' => $submittedAt->toDateTimeString(),
                    'notes' => $confirmationInput['notes'] ?? null,
                    'application_channel' => $confirmationInput['application_channel'],
                    'external_reference' => $confirmationInput['external_reference'] ?? null,
                ],
            );

            $confirmation = JobApplicationSubmissionConfirmation::create([
                'job_application_id' => $application->getKey(),
                'recorded_by' => $actor->getKey(),
                'client_reference' => $confirmationInput['client_reference'],
                'submitted_at' => $submittedAt,
                'application_channel' => $confirmationInput['application_channel'],
                'external_reference' => $confirmationInput['external_reference'] ?? null,
                'destination_url' => $confirmationInput['destination_url'] ?? null,
                'generated_document_version_id' => $application->submitted_generated_document_version_id,
                'source_resume_version_id' => $application->submitted_source_resume_version_id,
                'document_version_number' => $application->submitted_document_version_number,
                'document_filename' => $application->submitted_document_filename,
                'document_checksum_sha256' => $application->submitted_document_checksum_sha256,
                'notes' => $confirmationInput['notes'] ?? null,
            ]);

            return $confirmation->load($this->relations());
        });
    }

    private function validatedConfirmation(array $input): array
    {
        $input = $this->normalizeInput($input);

        return Validator::make(['confirmation' => $input], [
            'confirmation' => [
                'required',
                'array:client_reference,submitted_at,application_channel,external_reference,destination_url,notes',
            ],
            'confirmation.client_reference' => ['required', 'string', 'max:100'],
            'confirmation.submitted_at' => ['required', 'date'],
            'confirmation.application_channel' => ['required', 'string', 'max:50'],
            'confirmation.external_reference' => ['nullable', 'string', 'max:255'],
            'confirmation.destination_url' => ['nullable', 'string', 'url', 'max:2048'],
            'confirmation.notes' => ['nullable', 'string', 'max:2000'],
        ])->after(function ($validator) use ($input): void {
            $destinationUrl = $input['destination_url'] ?? null;

            if (
                is_string($destinationUrl)
                && ! str_starts_with($destinationUrl, 'https://')
                && ! str_starts_with($destinationUrl, 'http://')
            ) {
                $validator->errors()->add(
                    'confirmation.destination_url',
                    'The destination URL must use HTTP or HTTPS.',
                );
            }
        })->validate()['confirmation'];
    }

    private function normalizeInput(array $input): array
    {
        foreach ([
            'client_reference',
            'application_channel',
            'external_reference',
            'destination_url',
        ] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = $this->nullableSquished($input[$field]);
            }
        }

        if (array_key_exists('submitted_at', $input) && is_string($input['submitted_at'])) {
            $input['submitted_at'] = trim($input['submitted_at']);
        }

        if (array_key_exists('notes', $input) && is_string($input['notes'])) {
            $input['notes'] = $this->nullableTrimmed($input['notes']);
        }

        return $input;
    }

    private function sameConfirmation(
        JobApplicationSubmissionConfirmation $existing,
        User $actor,
        array $input,
        CarbonImmutable $submittedAt,
    ): bool {
        return (int) $existing->recorded_by === (int) $actor->getKey()
            && $existing->client_reference === $input['client_reference']
            && $existing->submitted_at->getTimestamp() === $submittedAt->getTimestamp()
            && $existing->application_channel === $input['application_channel']
            && $existing->external_reference === ($input['external_reference'] ?? null)
            && $existing->destination_url === ($input['destination_url'] ?? null)
            && $existing->notes === ($input['notes'] ?? null);
    }

    private function relations(): array
    {
        return [
            'jobApplication',
            'recordedBy',
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
