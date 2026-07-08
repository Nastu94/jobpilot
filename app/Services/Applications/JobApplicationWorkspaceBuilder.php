<?php

namespace App\Services\Applications;

use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobApplicationInteraction;
use App\Models\JobApplicationScheduledEvent;
use App\Models\JobApplicationSubmissionConfirmation;
use Carbon\CarbonImmutable;

class JobApplicationWorkspaceBuilder
{
    private const TERMINAL_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
        private readonly JobApplicationFollowUpContextBuilder $followUpBuilder,
        private readonly JobApplicationTimelineBuilder $timelineBuilder,
    ) {
    }

    public function build(
        JobApplication $application,
        CarbonImmutable $referenceAt,
        int $upcomingDays,
        int $timelineLimit,
    ): array {
        $readiness = $this->readinessChecker->check($application);
        $followUp = $this->followUpBuilder->build(
            $application,
            $referenceAt,
            $upcomingDays,
        );
        $timeline = $this->timelineBuilder->build(
            $application,
            JobApplicationTimelineBuilder::EVENT_TYPES,
            'desc',
            $timelineLimit,
        );
        $latestInteraction = $application->interactions->last();
        $nextPlannedEvent = $application->scheduledEvents
            ->firstWhere('status', 'planned');
        $plannedEventsTotal = $application->scheduledEvents
            ->where('status', 'planned')
            ->count();
        $terminal = in_array(
            $application->status,
            self::TERMINAL_STATUSES,
            true,
        );
        $submissionConfirmation = $application->submissionConfirmation;

        return [
            'application' => $this->application($application),
            'posting' => $this->posting($application),
            'document' => $this->document($application),
            'submission_readiness' => $readiness,
            'submission_confirmation' => $this->submissionConfirmation(
                $submissionConfirmation,
            ),
            'follow_up' => $followUp,
            'latest_interaction' => $this->interaction($latestInteraction),
            'next_planned_event' => $this->scheduledEvent($nextPlannedEvent),
            'signals' => [
                'is_terminal' => $terminal,
                'is_active' => ! $terminal,
                'submission_ready' => $readiness['ready'],
                'has_submission_confirmation' => $submissionConfirmation !== null,
                'has_follow_up' => $followUp['follow_up_at'] !== null,
                'has_planned_event' => $nextPlannedEvent !== null,
                'has_event_replacements' => $application
                    ->scheduledEventReplacements
                    ->isNotEmpty(),
            ],
            'counts' => [
                'submission_confirmations_total' => $submissionConfirmation === null ? 0 : 1,
                'interactions_total' => $application->interactions->count(),
                'scheduled_events_total' => $application->scheduledEvents->count(),
                'planned_events_total' => $plannedEventsTotal,
                'event_replacements_total' => $application
                    ->scheduledEventReplacements
                    ->count(),
                'timeline_events_total' => $timeline['summary']['available_total'],
                'timeline_events_returned' => $timeline['summary']['returned_total'],
            ],
            'timeline' => $timeline,
        ];
    }

    private function application(JobApplication $application): array
    {
        return [
            'id' => $application->getKey(),
            'profile_id' => $application->profile_id,
            'job_posting_id' => $application->job_posting_id,
            'job_title' => $application->job_title,
            'company_name' => $application->company_name,
            'status' => $application->status,
            'application_channel' => $application->application_channel,
            'external_reference' => $application->external_reference,
            'applied_at' => $application->applied_at?->toISOString(),
            'next_action_at' => $application->next_action_at?->toISOString(),
            'notes' => $application->notes,
            'resume_version_id' => $application->resume_version_id,
            'generated_document_version_id' => $application->generated_document_version_id,
            'created_at' => $application->created_at?->toISOString(),
            'updated_at' => $application->updated_at?->toISOString(),
        ];
    }

    private function posting(JobApplication $application): ?array
    {
        $posting = $application->jobPosting;

        if ($posting === null) {
            return null;
        }

        return [
            'id' => $posting->getKey(),
            'title' => $posting->title,
            'company_name' => $posting->company_name,
            'source' => $posting->source,
            'external_id' => $posting->external_id,
            'source_url' => $posting->source_url,
            'location' => $posting->location,
            'country_code' => $posting->country_code,
            'remote_type' => $posting->remote_type,
            'employment_type' => $posting->employment_type,
            'seniority' => $posting->seniority,
            'status' => $posting->status,
            'published_at' => $posting->published_at?->toISOString(),
            'expires_at' => $posting->expires_at?->toISOString(),
        ];
    }

    private function document(JobApplication $application): ?array
    {
        if ($application->status === 'draft') {
            return $this->selectedDocument($application->generatedDocumentVersion);
        }

        return $this->submittedDocument($application);
    }

    private function selectedDocument(
        ?GeneratedDocumentVersion $version,
    ): ?array {
        if ($version === null) {
            return null;
        }

        return [
            'document_source' => 'selected_version',
            'generated_document_version_id' => $version->getKey(),
            'source_resume_version_id' => $version->source_resume_version_id,
            'version_number' => $version->version_number,
            'review_status' => $version->review_status,
            'contains_unverified_claims' => (bool) $version->contains_unverified_claims,
            'filename' => $version->filename,
            'mime_type' => $version->mime_type,
            'file_size' => $version->file_size,
            'checksum_sha256' => $version->checksum_sha256,
            'reviewed_at' => $version->reviewed_at?->toISOString(),
        ];
    }

    private function submittedDocument(JobApplication $application): ?array
    {
        if ($application->submitted_generated_document_version_id === null) {
            return null;
        }

        return [
            'document_source' => 'submitted_snapshot',
            'generated_document_version_id' => $application->submitted_generated_document_version_id,
            'source_resume_version_id' => $application->submitted_source_resume_version_id,
            'version_number' => $application->submitted_document_version_number,
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
            'filename' => $application->submitted_document_filename,
            'mime_type' => $application->submitted_document_mime_type,
            'file_size' => $application->submitted_document_file_size,
            'checksum_sha256' => $application->submitted_document_checksum_sha256,
            'reviewed_at' => $application->submitted_document_reviewed_at?->toISOString(),
        ];
    }

    private function submissionConfirmation(
        ?JobApplicationSubmissionConfirmation $confirmation,
    ): ?array {
        if ($confirmation === null) {
            return null;
        }

        return [
            'id' => $confirmation->getKey(),
            'client_reference' => $confirmation->client_reference,
            'submitted_at' => $confirmation->submitted_at->toISOString(),
            'application_channel' => $confirmation->application_channel,
            'external_reference' => $confirmation->external_reference,
            'destination_url' => $confirmation->destination_url,
            'generated_document_version_id' => $confirmation->generated_document_version_id,
            'source_resume_version_id' => $confirmation->source_resume_version_id,
            'document_version_number' => $confirmation->document_version_number,
            'document_filename' => $confirmation->document_filename,
            'document_checksum_sha256' => $confirmation->document_checksum_sha256,
            'notes' => $confirmation->notes,
            'recorded_by' => $confirmation->recordedBy === null
                ? null
                : [
                    'id' => $confirmation->recordedBy->getKey(),
                    'name' => $confirmation->recordedBy->name,
                ],
        ];
    }

    private function interaction(
        ?JobApplicationInteraction $interaction,
    ): ?array {
        if ($interaction === null) {
            return null;
        }

        return [
            'id' => $interaction->getKey(),
            'client_reference' => $interaction->client_reference,
            'interaction_type' => $interaction->interaction_type,
            'direction' => $interaction->direction,
            'subject' => $interaction->subject,
            'contact_name' => $interaction->contact_name,
            'contact_email' => $interaction->contact_email,
            'notes' => $interaction->notes,
            'occurred_at' => $interaction->occurred_at->toISOString(),
            'recorded_by' => $interaction->recordedBy === null
                ? null
                : [
                    'id' => $interaction->recordedBy->getKey(),
                    'name' => $interaction->recordedBy->name,
                ],
        ];
    }

    private function scheduledEvent(
        ?JobApplicationScheduledEvent $event,
    ): ?array {
        if ($event === null) {
            return null;
        }

        return [
            'id' => $event->getKey(),
            'replaces_scheduled_event_id' => $event
                ->replacesRecord
                ?->previous_scheduled_event_id,
            'replaced_by_scheduled_event_id' => $event
                ->replacementRecord
                ?->replacement_scheduled_event_id,
            'client_reference' => $event->client_reference,
            'event_type' => $event->event_type,
            'title' => $event->title,
            'starts_at' => $event->starts_at->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
            'location' => $event->location,
            'meeting_url' => $event->meeting_url,
            'contact_name' => $event->contact_name,
            'contact_email' => $event->contact_email,
            'notes' => $event->notes,
            'status' => $event->status,
        ];
    }
}
