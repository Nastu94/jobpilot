<?php

namespace App\Actions\JobPostings;

use App\Actions\Matching\CalculateDeterministicMatch;
use App\Models\JobPosting;
use App\Models\MatchAnalysis;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RunJobPostingMatch
{
    public function __construct(
        private readonly CalculateDeterministicMatch $calculate,
    ) {
    }

    public function execute(JobPosting $jobPosting, User $actor): MatchAnalysis
    {
        $jobPosting = JobPosting::query()
            ->with('profile')
            ->findOrFail($jobPosting->getKey());

        if ((int) $jobPosting->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job posting.');
        }

        return $this->calculate->execute($jobPosting->profile, $jobPosting);
    }
}
