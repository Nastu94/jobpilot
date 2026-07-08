<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\JobApplicationIntegrityAuditor;
use Illuminate\Auth\Access\AuthorizationException;

class InspectJobApplicationIntegrity
{
    public function __construct(
        private readonly JobApplicationIntegrityAuditor $auditor,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
    ): array {
        $application = JobApplication::query()
            ->with([
                'profile',
                'resumeVersion.resume',
                'generatedDocumentVersion.generatedDocument',
                'generatedDocumentVersion.sourceResumeVersion.resume',
                'statusHistory.changedBy',
                'submissionConfirmation.recordedBy',
                'scheduledEvents.statusHistory.changedBy',
                'scheduledEventReplacements.previousEvent',
                'scheduledEventReplacements.replacementEvent',
            ])
            ->findOrFail($application->getKey());

        if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job application.');
        }

        return $this->auditor->audit($application);
    }
}
