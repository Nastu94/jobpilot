<?php

namespace App\Services\Applications;

use App\Models\JobApplication;

class JobApplicationManualSubmissionHandoffBuilder
{
    public function build(JobApplication $application, array $readiness): array
    {
        $posting = $application->jobPosting;
        $warnings = [];
        $sourceUrl = $this->nullableTrimmed($posting?->source_url);
        $applicationChannel = $this->nullableTrimmed($application->application_channel);
        $externalReference = $this->nullableTrimmed($application->external_reference);

        if ($posting === null) {
            $warnings[] = $this->warning(
                'job_posting_missing',
                'The application is not linked to a job posting.',
            );
        }

        if ($sourceUrl === null) {
            $warnings[] = $this->warning(
                'source_url_missing',
                'No job posting source URL is available.',
            );
        } elseif (! $this->isHttpUrl($sourceUrl)) {
            $warnings[] = $this->warning(
                'source_url_not_http',
                'The job posting source URL is not an HTTP or HTTPS URL.',
            );
        }

        if ($applicationChannel === null) {
            $warnings[] = $this->warning(
                'application_channel_missing',
                'No application channel has been recorded.',
            );
        }

        if ($externalReference === null) {
            $warnings[] = $this->warning(
                'external_reference_missing',
                'No external application reference has been recorded.',
            );
        }

        return [
            'application' => [
                'id' => $application->getKey(),
                'profile_id' => $application->profile_id,
                'job_posting_id' => $application->job_posting_id,
                'job_title' => $application->job_title,
                'company_name' => $application->company_name,
                'status' => $application->status,
                'application_channel' => $applicationChannel,
                'external_reference' => $externalReference,
                'generated_document_version_id' => $application->generated_document_version_id,
                'resume_version_id' => $application->resume_version_id,
            ],
            'destination' => [
                'source' => $posting?->source,
                'source_url' => $sourceUrl,
                'external_id' => $posting?->external_id,
                'application_channel' => $applicationChannel,
                'external_reference' => $externalReference,
            ],
            'submission_readiness' => $readiness,
            'manual_steps' => [
                'verify_submission_destination',
                'verify_application_details',
                'upload_approved_targeted_resume',
                'complete_external_form_without_inventing_facts',
                'review_external_submission',
                'submit_application_manually',
                'record_submission_in_jobpilot',
            ],
            'warnings' => $warnings,
        ];
    }

    private function warning(string $code, string $message): array
    {
        return [
            'code' => $code,
            'message' => $message,
        ];
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
