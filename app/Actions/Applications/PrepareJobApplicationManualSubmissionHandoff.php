<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\ApplicationSubmissionReadinessChecker;
use App\Services\Applications\AuditedJobApplicationDocumentReader;
use App\Services\Applications\JobApplicationManualSubmissionHandoffBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareJobApplicationManualSubmissionHandoff
{
    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
        private readonly JobApplicationManualSubmissionHandoffBuilder $handoffBuilder,
        private readonly AuditedJobApplicationDocumentReader $documentReader,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
    ): array {
        return DB::transaction(function () use ($application, $actor): array {
            $application = JobApplication::query()
                ->with([
                    'profile',
                    'jobPosting',
                    'resumeVersion.resume',
                    'generatedDocumentVersion.generatedDocument',
                    'generatedDocumentVersion.sourceResumeVersion.resume',
                ])
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            $readiness = $this->readinessChecker->check($application);

            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'manual_submission_handoff' => array_column(
                        $readiness['blockers'],
                        'message',
                    ),
                ]);
            }

            $handoff = $this->handoffBuilder->build($application, $readiness);
            $file = $this->documentReader->read($application, $actor);

            return array_merge($handoff, [
                'document' => [
                    'application_id' => $file['application_id'],
                    'document_source' => $file['document_source'],
                    'generated_document_version_id' => $file['generated_document_version_id'],
                    'source_resume_version_id' => $file['source_resume_version_id'],
                    'filename' => $file['filename'],
                    'mime_type' => $file['mime_type'],
                    'file_size' => $file['file_size'],
                    'checksum_sha256' => $file['checksum_sha256'],
                    'contents' => $file['contents'],
                    'access_history_id' => $file['access_history_id'],
                    'accessed_at' => $file['accessed_at'],
                ],
            ]);
        });
    }
}
