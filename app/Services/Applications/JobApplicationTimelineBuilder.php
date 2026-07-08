<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationDocumentAccessHistory;
use App\Models\JobApplicationDocumentVersionHistory;
use App\Models\JobApplicationInteraction;
use App\Models\JobApplicationScheduledEvent;
use App\Models\JobApplicationScheduledEventHistory;
use App\Models\JobApplicationStatusHistory;
use App\Models\JobApplicationTrackingHistory;
use App\Models\User;
use Carbon\CarbonInterface;

class JobApplicationTimelineBuilder
{
    public const TYPE_STATUS_CHANGED = 'status_changed';

    public const TYPE_TRACKING_UPDATED = 'tracking_updated';

    public const TYPE_INTERACTION_RECORDED = 'interaction_recorded';

    public const TYPE_SCHEDULED_EVENT_CHANGED = 'scheduled_event_changed';

    public const TYPE_DOCUMENT_VERSION_SELECTED = 'document_version_selected';

    public const TYPE_DOCUMENT_ACCESSED = 'document_accessed';

    public const EVENT_TYPES = [
        self::TYPE_STATUS_CHANGED,
        self::TYPE_TRACKING_UPDATED,
        self::TYPE_INTERACTION_RECORDED,
        self::TYPE_SCHEDULED_EVENT_CHANGED,
        self::TYPE_DOCUMENT_VERSION_SELECTED,
        self::TYPE_DOCUMENT_ACCESSED,
    ];

    private const TYPE_PRIORITY = [
        self::TYPE_STATUS_CHANGED => 10,
        self::TYPE_TRACKING_UPDATED => 20,
        self::TYPE_INTERACTION_RECORDED => 25,
        self::TYPE_SCHEDULED_EVENT_CHANGED => 27,
        self::TYPE_DOCUMENT_VERSION_SELECTED => 30,
        self::TYPE_DOCUMENT_ACCESSED => 40,
    ];

    public function build(
        JobApplication $application,
        array $eventTypes,
        string $direction,
        int $limit,
    ): array {
        $events = [];

        foreach ($application->statusHistory as $history) {
            $events[] = $this->statusEvent($history);
        }

        foreach ($application->trackingHistory as $history) {
            $events[] = $this->trackingEvent($history);
        }

        foreach ($application->interactions as $interaction) {
            $events[] = $this->interactionEvent($interaction);
        }

        foreach ($application->scheduledEvents as $scheduledEvent) {
            foreach ($scheduledEvent->statusHistory as $history) {
                $events[] = $this->scheduledEventChange(
                    $scheduledEvent,
                    $history,
                );
            }
        }

        foreach ($application->documentVersionHistory as $history) {
            $events[] = $this->documentSelectionEvent($history);
        }

        foreach ($application->documentAccessHistory as $history) {
            $events[] = $this->documentAccessEvent($history);
        }

        $availableByType = $this->countsByType($events);
        $matching = array_values(array_filter(
            $events,
            fn (array $event): bool => in_array($event['event_type'], $eventTypes, true),
        ));

        usort(
            $matching,
            fn (array $left, array $right): int => $this->compareEvents(
                $left,
                $right,
                $direction,
            ),
        );

        $returned = array_slice($matching, 0, $limit);
        $returnedByType = $this->countsByType($returned);
        $returned = array_map(function (array $event): array {
            unset($event['_sort_timestamp'], $event['_sort_priority']);

            return $event;
        }, $returned);

        return [
            'application' => [
                'id' => $application->getKey(),
                'profile_id' => $application->profile_id,
                'job_posting_id' => $application->job_posting_id,
                'job_title' => $application->job_title,
                'company_name' => $application->company_name,
                'status' => $application->status,
                'applied_at' => $application->applied_at?->toISOString(),
                'next_action_at' => $application->next_action_at?->toISOString(),
                'generated_document_version_id' => $application->generated_document_version_id,
                'resume_version_id' => $application->resume_version_id,
                'submitted_generated_document_version_id' => $application->submitted_generated_document_version_id,
                'submitted_source_resume_version_id' => $application->submitted_source_resume_version_id,
            ],
            'filters' => [
                'event_types' => array_values($eventTypes),
                'direction' => $direction,
                'limit' => $limit,
            ],
            'summary' => [
                'available_total' => count($events),
                'matching_total' => count($matching),
                'returned_total' => count($returned),
                'available_by_type' => $availableByType,
                'returned_by_type' => $returnedByType,
            ],
            'events' => $returned,
        ];
    }

    private function statusEvent(JobApplicationStatusHistory $history): array
    {
        return $this->event(
            self::TYPE_STATUS_CHANGED,
            $history->getKey(),
            $history->changed_at,
            $history->changedBy,
            'application_status_changed',
            [
                'from_status' => $history->from_status,
                'status' => $history->status,
                'notes' => $history->notes,
            ],
        );
    }

    private function trackingEvent(JobApplicationTrackingHistory $history): array
    {
        return $this->event(
            self::TYPE_TRACKING_UPDATED,
            $history->getKey(),
            $history->changed_at,
            $history->changedBy,
            'application_tracking_updated',
            [
                'change_source' => $history->change_source,
                'before' => [
                    'application_channel' => $history->previous_application_channel,
                    'external_reference' => $history->previous_external_reference,
                    'next_action_at' => $history->previous_next_action_at?->toISOString(),
                    'notes' => $history->previous_notes,
                ],
                'after' => [
                    'application_channel' => $history->application_channel,
                    'external_reference' => $history->external_reference,
                    'next_action_at' => $history->next_action_at?->toISOString(),
                    'notes' => $history->notes,
                ],
            ],
        );
    }

    private function interactionEvent(JobApplicationInteraction $interaction): array
    {
        return $this->event(
            self::TYPE_INTERACTION_RECORDED,
            $interaction->getKey(),
            $interaction->occurred_at,
            $interaction->recordedBy,
            'application_interaction_recorded',
            [
                'client_reference' => $interaction->client_reference,
                'interaction_type' => $interaction->interaction_type,
                'direction' => $interaction->direction,
                'subject' => $interaction->subject,
                'contact_name' => $interaction->contact_name,
                'contact_email' => $interaction->contact_email,
                'notes' => $interaction->notes,
            ],
        );
    }

    private function scheduledEventChange(
        JobApplicationScheduledEvent $scheduledEvent,
        JobApplicationScheduledEventHistory $history,
    ): array {
        return $this->event(
            self::TYPE_SCHEDULED_EVENT_CHANGED,
            $history->getKey(),
            $history->changed_at,
            $history->changedBy,
            'application_scheduled_event_changed',
            [
                'scheduled_event_id' => $scheduledEvent->getKey(),
                'client_reference' => $scheduledEvent->client_reference,
                'event_type' => $scheduledEvent->event_type,
                'title' => $scheduledEvent->title,
                'starts_at' => $scheduledEvent->starts_at->toISOString(),
                'ends_at' => $scheduledEvent->ends_at?->toISOString(),
                'location' => $scheduledEvent->location,
                'meeting_url' => $scheduledEvent->meeting_url,
                'contact_name' => $scheduledEvent->contact_name,
                'contact_email' => $scheduledEvent->contact_email,
                'from_status' => $history->from_status,
                'status' => $history->status,
                'event_notes' => $scheduledEvent->notes,
                'change_notes' => $history->notes,
            ],
        );
    }

    private function documentSelectionEvent(
        JobApplicationDocumentVersionHistory $history,
    ): array {
        return $this->event(
            self::TYPE_DOCUMENT_VERSION_SELECTED,
            $history->getKey(),
            $history->changed_at,
            $history->changedBy,
            'application_document_version_selected',
            [
                'generated_document_id' => $history->generated_document_id,
                'before' => [
                    'generated_document_version_id' => $history->previous_generated_document_version_id,
                    'resume_version_id' => $history->previous_resume_version_id,
                    'version_number' => $history->previous_version_number,
                    'checksum_sha256' => $history->previous_checksum_sha256,
                    'reviewed_content_sha256' => $history->previous_reviewed_content_sha256,
                ],
                'after' => [
                    'generated_document_version_id' => $history->generated_document_version_id,
                    'resume_version_id' => $history->resume_version_id,
                    'version_number' => $history->version_number,
                    'checksum_sha256' => $history->checksum_sha256,
                    'reviewed_content_sha256' => $history->reviewed_content_sha256,
                ],
                'notes' => $history->notes,
            ],
        );
    }

    private function documentAccessEvent(
        JobApplicationDocumentAccessHistory $history,
    ): array {
        return $this->event(
            self::TYPE_DOCUMENT_ACCESSED,
            $history->getKey(),
            $history->accessed_at,
            $history->accessedBy,
            'application_document_accessed',
            [
                'document_source' => $history->document_source,
                'generated_document_version_id' => $history->generated_document_version_id,
                'source_resume_version_id' => $history->source_resume_version_id,
                'filename' => $history->filename,
                'mime_type' => $history->mime_type,
                'file_size' => $history->file_size,
                'checksum_sha256' => $history->checksum_sha256,
            ],
        );
    }

    private function event(
        string $eventType,
        int $sourceId,
        CarbonInterface $occurredAt,
        ?User $actor,
        string $summaryCode,
        array $details,
    ): array {
        return [
            'event_key' => $eventType.':'.$sourceId,
            'event_type' => $eventType,
            'source_id' => $sourceId,
            'occurred_at' => $occurredAt->toISOString(),
            'actor' => $actor === null
                ? null
                : [
                    'id' => $actor->getKey(),
                    'name' => $actor->name,
                ],
            'summary_code' => $summaryCode,
            'details' => $details,
            '_sort_timestamp' => $occurredAt->getTimestamp(),
            '_sort_priority' => self::TYPE_PRIORITY[$eventType],
        ];
    }

    private function compareEvents(
        array $left,
        array $right,
        string $direction,
    ): int {
        if ($left['_sort_timestamp'] !== $right['_sort_timestamp']) {
            $comparison = $left['_sort_timestamp'] <=> $right['_sort_timestamp'];

            return $direction === 'asc' ? $comparison : -$comparison;
        }

        $priorityComparison = $left['_sort_priority'] <=> $right['_sort_priority'];

        return $priorityComparison !== 0
            ? $priorityComparison
            : $left['source_id'] <=> $right['source_id'];
    }

    private function countsByType(array $events): array
    {
        $counts = array_fill_keys(self::EVENT_TYPES, 0);

        foreach ($events as $event) {
            $counts[$event['event_type']]++;
        }

        return $counts;
    }
}
