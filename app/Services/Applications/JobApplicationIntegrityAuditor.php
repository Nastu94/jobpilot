<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationScheduledEvent;
use Illuminate\Validation\ValidationException;

class JobApplicationIntegrityAuditor
{
    private const APPLIED_LIFECYCLE_STATUSES = [
        'applied',
        'screening',
        'assessment',
        'interview',
        'offer',
        'hired',
        'rejected',
    ];

    private const SUBMITTED_SNAPSHOT_FIELDS = [
        'submitted_generated_document_version_id',
        'submitted_source_resume_version_id',
        'submitted_document_version_number',
        'submitted_document_filename',
        'submitted_document_mime_type',
        'submitted_document_file_size',
        'submitted_document_checksum_sha256',
        'submitted_document_content_sha256',
        'submitted_document_storage_disk',
        'submitted_document_storage_path',
        'submitted_document_generator_key',
        'submitted_document_generator_version',
        'submitted_document_reviewed_at',
    ];

    public function __construct(
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
        private readonly JobApplicationDocumentFileReader $documentReader,
    ) {
    }

    public function audit(JobApplication $application): array
    {
        $issues = [];

        $this->auditApplicationState($application, $issues);
        $this->auditStatusHistory($application, $issues);
        $this->auditSubmissionConfirmation($application, $issues);
        $this->auditScheduledEvents($application, $issues);
        $this->auditEventReplacements($application, $issues);
        $this->auditSubmittedDocumentFile($application, $issues);

        usort($issues, function (array $left, array $right): int {
            $severity = ['error' => 0, 'warning' => 1];
            $comparison = $severity[$left['severity']]
                <=> $severity[$right['severity']];

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strcmp($left['code'], $right['code']);

            if ($comparison !== 0) {
                return $comparison;
            }

            return strcmp(
                json_encode($left['context'], JSON_THROW_ON_ERROR),
                json_encode($right['context'], JSON_THROW_ON_ERROR),
            );
        });

        $errors = count(array_filter(
            $issues,
            fn (array $issue): bool => $issue['severity'] === 'error',
        ));
        $warnings = count($issues) - $errors;

        return [
            'application_id' => $application->getKey(),
            'application_status' => $application->status,
            'integrity_status' => $errors > 0
                ? 'invalid'
                : ($warnings > 0 ? 'warning' : 'healthy'),
            'healthy' => $issues === [],
            'summary' => [
                'errors' => $errors,
                'warnings' => $warnings,
                'total' => count($issues),
            ],
            'issues' => $issues,
        ];
    }

    private function auditApplicationState(
        JobApplication $application,
        array &$issues,
    ): void {
        if (! $this->statusWorkflow->supports($application->status)) {
            $this->error(
                $issues,
                'unsupported_application_status',
                'The application status is not supported by the workflow.',
                ['status' => $application->status],
            );
        }

        if (
            $this->statusWorkflow->isTerminal($application->status)
            && $application->next_action_at !== null
        ) {
            $this->error(
                $issues,
                'terminal_application_has_next_action',
                'A terminal application must not retain a next action date.',
            );
        }

        if ($application->status === 'draft' && $application->applied_at !== null) {
            $this->error(
                $issues,
                'draft_application_has_applied_at',
                'A draft application must not have an applied date.',
            );
        }

        if (
            in_array($application->status, self::APPLIED_LIFECYCLE_STATUSES, true)
            && $application->applied_at === null
        ) {
            $this->error(
                $issues,
                'applied_lifecycle_missing_applied_at',
                'An application in the applied lifecycle must have an applied date.',
            );
        }

        $presentSnapshotFields = array_values(array_filter(
            self::SUBMITTED_SNAPSHOT_FIELDS,
            fn (string $field): bool => $this->hasValue(
                $application->getAttribute($field),
            ),
        ));

        if (
            $presentSnapshotFields !== []
            && count($presentSnapshotFields) !== count(self::SUBMITTED_SNAPSHOT_FIELDS)
        ) {
            $this->error(
                $issues,
                'submitted_snapshot_incomplete',
                'The submitted document snapshot is only partially populated.',
                [
                    'present_fields' => $presentSnapshotFields,
                    'missing_fields' => array_values(array_diff(
                        self::SUBMITTED_SNAPSHOT_FIELDS,
                        $presentSnapshotFields,
                    )),
                ],
            );
        }

        if (
            in_array($application->status, self::APPLIED_LIFECYCLE_STATUSES, true)
            && count($presentSnapshotFields) !== count(self::SUBMITTED_SNAPSHOT_FIELDS)
        ) {
            $this->error(
                $issues,
                'applied_lifecycle_missing_submitted_snapshot',
                'An application in the applied lifecycle must have a complete submitted document snapshot.',
            );
        }

        if (
            $application->status === 'draft'
            && $presentSnapshotFields !== []
        ) {
            $this->error(
                $issues,
                'draft_application_has_submitted_snapshot',
                'A draft application must not have a submitted document snapshot.',
            );
        }
    }

    private function auditStatusHistory(
        JobApplication $application,
        array &$issues,
    ): void {
        $history = $application->statusHistory->values();

        if ($history->isEmpty()) {
            if ($application->status !== 'draft') {
                $this->error(
                    $issues,
                    'non_draft_application_missing_status_history',
                    'A non-draft application must have status history.',
                );
            }

            return;
        }

        foreach ($history as $index => $entry) {
            if ($index > 0) {
                $previous = $history[$index - 1];

                if ($entry->from_status !== $previous->status) {
                    $this->error(
                        $issues,
                        'status_history_chain_broken',
                        'A status history entry does not continue from the preceding status.',
                        [
                            'history_id' => $entry->getKey(),
                            'expected_from_status' => $previous->status,
                            'actual_from_status' => $entry->from_status,
                        ],
                    );
                }
            }
        }

        $latest = $history->last();

        if ($latest->status !== $application->status) {
            $this->error(
                $issues,
                'latest_status_history_mismatch',
                'The latest status history entry does not match the current application status.',
                [
                    'history_status' => $latest->status,
                    'application_status' => $application->status,
                ],
            );
        }
    }

    private function auditSubmissionConfirmation(
        JobApplication $application,
        array &$issues,
    ): void {
        $confirmation = $application->submissionConfirmation;

        if ($confirmation === null) {
            return;
        }

        if ($application->status === 'draft') {
            $this->error(
                $issues,
                'draft_application_has_submission_confirmation',
                'A draft application must not have a submission confirmation.',
            );
        }

        if (
            $application->applied_at === null
            || $confirmation->submitted_at->getTimestamp()
                !== $application->applied_at->getTimestamp()
        ) {
            $this->error(
                $issues,
                'submission_confirmation_time_mismatch',
                'The submission confirmation time does not match the application applied date.',
            );
        }

        foreach ([
            'application_channel' => 'application_channel',
            'external_reference' => 'external_reference',
            'generated_document_version_id' => 'submitted_generated_document_version_id',
            'source_resume_version_id' => 'submitted_source_resume_version_id',
            'document_version_number' => 'submitted_document_version_number',
            'document_filename' => 'submitted_document_filename',
            'document_checksum_sha256' => 'submitted_document_checksum_sha256',
        ] as $confirmationField => $applicationField) {
            if (
                $confirmation->getAttribute($confirmationField)
                !== $application->getAttribute($applicationField)
            ) {
                $this->error(
                    $issues,
                    'submission_confirmation_snapshot_mismatch',
                    'The submission confirmation does not match the application submission snapshot.',
                    [
                        'confirmation_field' => $confirmationField,
                        'application_field' => $applicationField,
                    ],
                );
            }
        }
    }

    private function auditScheduledEvents(
        JobApplication $application,
        array &$issues,
    ): void {
        $plannedIds = [];

        foreach ($application->scheduledEvents as $event) {
            if ($event->status === 'planned') {
                $plannedIds[] = $event->getKey();

                if (
                    $event->resolved_at !== null
                    || $event->resolved_by !== null
                    || $event->resolution_notes !== null
                ) {
                    $this->error(
                        $issues,
                        'planned_event_has_resolution_data',
                        'A planned event must not contain resolution data.',
                        ['scheduled_event_id' => $event->getKey()],
                    );
                }
            } elseif (
                in_array($event->status, ['completed', 'cancelled'], true)
                && $event->resolved_at === null
            ) {
                $this->error(
                    $issues,
                    'resolved_event_missing_resolved_at',
                    'A completed or cancelled event must have a resolution date.',
                    ['scheduled_event_id' => $event->getKey()],
                );
            }

            $latestHistory = $event->statusHistory->last();

            if ($latestHistory === null || $latestHistory->status !== $event->status) {
                $this->error(
                    $issues,
                    'scheduled_event_history_mismatch',
                    'The latest event history does not match the current event status.',
                    ['scheduled_event_id' => $event->getKey()],
                );
            }
        }

        if (
            $plannedIds !== []
            && $this->statusWorkflow->isTerminal($application->status)
        ) {
            $this->warning(
                $issues,
                'terminal_application_has_planned_events',
                'A terminal application still has planned events that should be resolved manually.',
                ['scheduled_event_ids' => $plannedIds],
            );
        }
    }

    private function auditEventReplacements(
        JobApplication $application,
        array &$issues,
    ): void {
        foreach ($application->scheduledEventReplacements as $replacement) {
            $previous = $replacement->previousEvent;
            $next = $replacement->replacementEvent;

            if ($previous === null || $next === null) {
                $this->error(
                    $issues,
                    'event_replacement_missing_event',
                    'An event replacement references a missing event.',
                    ['replacement_id' => $replacement->getKey()],
                );

                continue;
            }

            if (
                (int) $previous->job_application_id !== (int) $application->getKey()
                || (int) $next->job_application_id !== (int) $application->getKey()
            ) {
                $this->error(
                    $issues,
                    'event_replacement_application_mismatch',
                    'An event replacement links events from another application.',
                    ['replacement_id' => $replacement->getKey()],
                );
            }

            if ($previous->status !== 'cancelled') {
                $this->error(
                    $issues,
                    'replaced_event_not_cancelled',
                    'The previous event in a replacement must be cancelled.',
                    [
                        'replacement_id' => $replacement->getKey(),
                        'scheduled_event_id' => $previous->getKey(),
                    ],
                );
            }

            if ($previous->getKey() === $next->getKey()) {
                $this->error(
                    $issues,
                    'event_replacement_self_reference',
                    'An event cannot replace itself.',
                    ['replacement_id' => $replacement->getKey()],
                );
            }
        }
    }

    private function auditSubmittedDocumentFile(
        JobApplication $application,
        array &$issues,
    ): void {
        if (! in_array(
            $application->status,
            self::APPLIED_LIFECYCLE_STATUSES,
            true,
        )) {
            return;
        }

        try {
            $this->documentReader->read($application);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error(
                        $issues,
                        'submitted_document_file_invalid',
                        $message,
                    );
                }
            }
        }
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null
            && (! is_string($value) || trim($value) !== '');
    }

    private function error(
        array &$issues,
        string $code,
        string $message,
        array $context = [],
    ): void {
        $this->issue($issues, 'error', $code, $message, $context);
    }

    private function warning(
        array &$issues,
        string $code,
        string $message,
        array $context = [],
    ): void {
        $this->issue($issues, 'warning', $code, $message, $context);
    }

    private function issue(
        array &$issues,
        string $severity,
        string $code,
        string $message,
        array $context,
    ): void {
        $issues[] = [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }
}
