<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\AuditedJobApplicationDocumentReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReadJobApplicationDocumentFile
{
    public function __construct(
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
                    'resumeVersion.resume',
                    'generatedDocumentVersion.generatedDocument',
                    'generatedDocumentVersion.sourceResumeVersion.resume',
                ])
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('The user does not own this job application.');
            }

            return $this->documentReader->read($application, $actor);
        });
    }
}
