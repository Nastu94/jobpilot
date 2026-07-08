<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\User;
use App\Services\Applications\ApplicationSubmissionReadinessChecker;
use App\Services\Applications\JobApplicationStatusWorkflow;
use App\Services\Applications\JobApplicationTrackingRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransitionJobApplicationStatus
{
    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
        private readonly JobApplicationTrackingRecorder $trackingRecorder,
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
        array $input,
    ): JobApplication {
        return DB::transaction(function () use ($application, $actor, $input): JobApplication {
            $application = JobApplication::query()
                ->with(['profile', 'jobPosting', 'generatedDocumentVersion'])
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            $transition = $this->validatedTransition($input);
            $targetStatus = $transition['status'];
            $currentStatus = $application->status;

            if ($targetStatus === $currentStatus) {
                return $application->fresh($this->applicationRelations());
            }

            $this->ensureTransitionIsAllowed($currentStatus, $targetStatus);

            if ($currentStatus === 'draft' && $targetStatus === 'applied') {
                $this->ensureApplicationIsReady($application);
            }

            $changedAt = isset($transition['changed_at'])
                ? CarbonImmutable::parse($transition['changed_at'])
                : CarbonImmutable::now();
            $latestHistory = JobApplicationStatusHistory::query()
                ->where('job_application_id', $application->getKey())
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latestHistory !== null && $changedAt->lt($latestHistory->changed_at)) {
                throw ValidationException::withMessages([
                    'changed_at' => 'The transition date cannot precede the latest status history entry.',
                ]);
            }

            if ($changedAt->isFuture()) {
                throw ValidationException::withMessages([
                    'changed_at' => 'A status transition cannot be recorded in the future.',
                ]);
            }

            $hasNextActionInput = array_key_exists('next_action_at', $transition);
            $nextActionAt = $hasNextActionInput && $transition['next_action_at'] !== null
                ? CarbonImmutable::parse($transition['next_action_at'])
                : null;

            if (
                $nextActionAt !== null
                && ! $this->statusWorkflow->isTerminal($targetStatus)
                && $nextActionAt->lt($changedAt)
            ) {
                throw ValidationException::withMessages([
                    'next_action_at' => 'The next action cannot precede the status transition.',
                ]);
            }

            $updates = [
                'status' => $targetStatus,
            ];

            if (array_key_exists('application_channel', $transition)) {
                $updates['application_channel'] = $this->nullableSquished(
                    $transition['application_channel'],
                );
            }

            if (array_key_exists('external_reference', $transition)) {
                $updates['external_reference'] = $this->nullableSquished(
                    $transition['external_reference'],
                );
            }

            if ($targetStatus === 'applied') {
                if ($application->applied_at === null) {
                    $updates['applied_at'] = $changedAt;
                }

                $updates = array_merge(
                    $updates,
                    $this->submissionSnapshot(
                        $application,
                        $changedAt,
                        $updates,
                    ),
                );
            }

            if ($this->statusWorkflow->isTerminal($targetStatus)) {
                $updates['next_action_at'] = null;
            } elseif ($hasNextActionInput) {
                $updates['next_action_at'] = $nextActionAt;
            }

            $trackingBefore = $this->trackingRecorder->snapshot($application);
            $application->forceFill($updates)->save();
            $this->trackingRecorder->record(
                $application,
                $actor,
                $changedAt,
                JobApplicationTrackingRecorder::SOURCE_STATUS_TRANSITION,
                $trackingBefore,
            );
            $application->statusHistory()->create([
                'from_status' => $currentStatus,
                'status' => $targetStatus,
                'changed_by' => $actor->getKey(),
                'changed_at' => $changedAt,
                'notes' => $this->nullableSquished($transition['notes'] ?? null),
            ]);

            return $application->fresh($this->applicationRelations());
        });
    }

    private function validatedTransition(array $input): array
    {
        return Validator::make(['transition' => $input], [
            'transition' => [
                'required',
                'array:status,changed_at,notes,application_channel,external_reference,next_action_at',
            ],
            'transition.status' => [
                'required',
                'string',
                Rule::in($this->statusWorkflow->statuses()),
            ],
            'transition.changed_at' => ['nullable', 'date'],
            'transition.notes' => ['nullable', 'string', 'max:2000'],
            'transition.application_channel' => ['nullable', 'string', 'max:50'],
            'transition.external_reference' => ['nullable', 'string', 'max:255'],
            'transition.next_action_at' => ['nullable', 'date'],
        ])->validate()['transition'];
    }

    private function ensureTransitionIsAllowed(string $currentStatus, string $targetStatus): void
    {
        if (! $this->statusWorkflow->supports($currentStatus)) {
            throw ValidationException::withMessages([
                'status' => 'The current application status is not supported by this workflow.',
            ]);
        }

        if (! $this->statusWorkflow->canTransition($currentStatus, $targetStatus)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'The transition from %s to %s is not allowed.',
                    $currentStatus,
                    $targetStatus,
                ),
            ]);
        }
    }

    private function ensureApplicationIsReady(JobApplication $application): void
    {
        $readiness = $this->readinessChecker->check($application);

        if ($readiness['ready']) {
            return;
        }

        throw ValidationException::withMessages([
            'submission_readiness' => array_column($readiness['blockers'], 'message'),
        ]);
    }

    private function submissionSnapshot(
        JobApplication $application,
        CarbonImmutable $capturedAt,
        array $pendingUpdates,
    ): array {
        $version = $application->generatedDocumentVersion;
        $posting = $application->jobPosting;
        $applicationChannel = array_key_exists(
            'application_channel',
            $pendingUpdates,
        )
            ? $pendingUpdates['application_channel']
            : $application->application_channel;
        $externalReference = array_key_exists(
            'external_reference',
            $pendingUpdates,
        )
            ? $pendingUpdates['external_reference']
            : $application->external_reference;

        return [
            'submitted_generated_document_version_id' => $version->getKey(),
            'submitted_source_resume_version_id' => $version->source_resume_version_id,
            'submitted_document_version_number' => $version->version_number,
            'submitted_document_filename' => $version->filename,
            'submitted_document_mime_type' => $version->mime_type,
            'submitted_document_file_size' => $version->file_size,
            'submitted_document_checksum_sha256' => $version->checksum_sha256,
            'submitted_document_content_sha256' => $version->reviewed_content_sha256,
            'submitted_document_storage_disk' => $version->storage_disk,
            'submitted_document_storage_path' => $version->storage_path,
            'submitted_document_generator_key' => $version->generator_key,
            'submitted_document_generator_version' => $version->generator_version,
            'submitted_document_reviewed_at' => $version->reviewed_at,
            'submitted_context_captured_at' => $capturedAt,
            'submitted_job_posting_id' => $application->job_posting_id,
            'submitted_job_title' => $this->nullableSquished($application->job_title),
            'submitted_company_name' => $this->nullableSquished($application->company_name),
            'submitted_job_source' => $this->nullableSquished($posting?->source),
            'submitted_job_location' => $this->nullableSquished($posting?->location),
            'submitted_job_country_code' => $this->nullableSquished($posting?->country_code),
            'submitted_job_remote_type' => $this->nullableSquished($posting?->remote_type),
            'submitted_job_employment_type' => $this->nullableSquished($posting?->employment_type),
            'submitted_job_seniority' => $this->nullableSquished($posting?->seniority),
            'submitted_application_channel' => $this->nullableSquished($applicationChannel),
            'submitted_external_reference' => $this->nullableSquished($externalReference),
        ];
    }

    private function applicationRelations(): array
    {
        return [
            'jobPosting',
            'resumeVersion',
            'generatedDocumentVersion',
            'statusHistory.changedBy',
            'trackingHistory.changedBy',
            'generatedDocuments',
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
}
