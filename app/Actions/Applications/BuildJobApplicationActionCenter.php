<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\JobApplicationActionCenterBuilder;
use Illuminate\Auth\Access\AuthorizationException;

class BuildJobApplicationActionCenter
{
    public function __construct(
        private readonly JobApplicationActionCenterBuilder $builder,
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
                'submissionConfirmation.recordedBy',
                'scheduledEvents',
            ])
            ->findOrFail($application->getKey());

        if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job application.');
        }

        return $this->builder->build($application);
    }
}
