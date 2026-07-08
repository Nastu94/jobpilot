<?php

namespace App\Actions\Applications;

use App\Models\JobApplication;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ReadJobApplicationSubmissionContextSnapshot
{
    public function execute(
        JobApplication $application,
        User $actor,
    ): array {
        $application = JobApplication::query()
            ->with('profile')
            ->findOrFail($application->getKey());

        if ((int) $application->profile->user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('The user does not own this job application.');
        }

        return [
            'application_id' => $application->getKey(),
            'application_status' => $application->status,
            'applied_at' => $application->applied_at?->toISOString(),
            'availability' => $this->availability($application),
            'captured_at' => $application->submitted_context_captured_at?->toISOString(),
            'snapshot' => [
                'job_posting_id' => $application->submitted_job_posting_id,
                'job_title' => $application->submitted_job_title,
                'company_name' => $application->submitted_company_name,
                'job_source' => $application->submitted_job_source,
                'job_location' => $application->submitted_job_location,
                'job_country_code' => $application->submitted_job_country_code,
                'job_remote_type' => $application->submitted_job_remote_type,
                'job_employment_type' => $application->submitted_job_employment_type,
                'job_seniority' => $application->submitted_job_seniority,
                'application_channel' => $application->submitted_application_channel,
                'external_reference' => $application->submitted_external_reference,
            ],
        ];
    }

    private function availability(JobApplication $application): string
    {
        if ($application->submitted_context_captured_at !== null) {
            return 'captured';
        }

        return $application->applied_at === null
            ? 'not_submitted'
            : 'legacy_or_missing';
    }
}
