<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use App\Services\Applications\JobApplicationIntegrityAuditor;
use App\Services\Applications\JobApplicationIntegrityRelations;
use Illuminate\Auth\Access\AuthorizationException;

class InspectJobApplicationIntegrity
{
    public function __construct(
        private readonly JobApplicationIntegrityAuditor $auditor,
        private readonly JobApplicationIntegrityRelations $relations,
    ) {
    }

    public function execute(
        JobApplication $application,
        User $actor,
    ): array {
        $application = JobApplication::query()
            ->with($this->relations->all())
            ->findOrFail($application->getKey());

        if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job application.');
        }

        return $this->auditor->audit($application);
    }
}
