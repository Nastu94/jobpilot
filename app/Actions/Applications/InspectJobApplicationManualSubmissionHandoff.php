<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\ApplicationSubmissionReadinessChecker;
use App\Services\Applications\JobApplicationManualSubmissionHandoffBuilder;
use Illuminate\Auth\Access\AuthorizationException;

class InspectJobApplicationManualSubmissionHandoff
{
    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
        private readonly JobApplicationManualSubmissionHandoffBuilder $handoffBuilder,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
    ): array {
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

        return $this->handoffBuilder->build(
            $application,
            $this->readinessChecker->check($application),
        );
    }
}
