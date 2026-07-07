<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\ApplicationSubmissionReadinessChecker;
use Illuminate\Auth\Access\AuthorizationException;

class InspectJobApplicationSubmissionReadiness
{
    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $checker,
    ) {
    }

    public function execute(JobApplication $application, User $actor): array
    {
        $application = JobApplication::query()
            ->with([
                'profile',
                'jobPosting',
                'resumeVersion.resume',
                'generatedDocumentVersion.generatedDocument',
                'generatedDocumentVersion.sourceResumeVersion.resume',
            ])
            ->findOrFail($application->getKey());

        if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job application.');
        }

        return $this->checker->check($application);
    }
}
