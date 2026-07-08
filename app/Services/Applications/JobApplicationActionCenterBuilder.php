<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use Illuminate\Validation\ValidationException;

class JobApplicationActionCenterBuilder
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    private const ACTION_ORDER = [
        'inspect_submission_handoff',
        'prepare_submission_handoff',
        'confirm_manual_submission',
        'read_application_document',
        'transition_status',
        'record_interaction',
        'schedule_event',
        'resolve_scheduled_event',
        'reschedule_scheduled_event',
    ];

    public function __construct(
        private readonly ApplicationSubmissionReadinessChecker $readinessChecker,
        private readonly JobApplicationDocumentFileReader $documentReader,
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function build(JobApplication $application): array
    {
        $readiness = $this->readinessChecker->check($application);
        $isDraft = $application->status === 'draft';
        $isTerminal = $this->statusWorkflow->isTerminal($application->status);
        $confirmation = $application->submissionConfirmation;
        $plannedEvents = $application->scheduledEvents
            ->where('status', 'planned')
            ->values();
        $actions = [
            $this->inspectHandoff($isDraft),
            $this->prepareHandoff($isDraft, $readiness),
            $this->confirmSubmission($application, $isDraft, $readiness),
            $this->readDocument($application),
            $this->transitionStatus($application, $readiness),
            $this->available('record_interaction'),
            $this->scheduleEvent($isTerminal),
            $this->resolveScheduledEvent($plannedEvents->pluck('id')->all()),
            $this->rescheduleScheduledEvent(
                $isTerminal,
                $plannedEvents->pluck('id')->all(),
            ),
        ];

        return [
            'application_id' => $application->getKey(),
            'application_status' => $application->status,
            'is_terminal' => $isTerminal,
            'submission_readiness' => $readiness,
            'submission_confirmation_id' => $confirmation?->getKey(),
            'planned_scheduled_event_ids' => $plannedEvents->pluck('id')->all(),
            'action_order' => self::ACTION_ORDER,
            'summary' => $this->summary($actions),
            'actions' => $actions,
        ];
    }

    private function inspectHandoff(bool $isDraft): array
    {
        if ($isDraft) {
            return $this->available('inspect_submission_handoff');
        }

        return $this->notApplicable(
            'inspect_submission_handoff',
            'application_not_draft',
            'Submission handoff inspection only applies to draft applications.',
        );
    }

    private function prepareHandoff(bool $isDraft, array $readiness): array
    {
        if (! $isDraft) {
            return $this->notApplicable(
                'prepare_submission_handoff',
                'application_not_draft',
                'Only a draft application can be prepared for submission.',
            );
        }

        if (! $readiness['ready']) {
            return $this->blocked(
                'prepare_submission_handoff',
                $readiness['blockers'],
            );
        }

        return $this->available('prepare_submission_handoff');
    }

    private function confirmSubmission(
        JobApplication $application,
        bool $isDraft,
        array $readiness,
    ): array {
        if ($application->submissionConfirmation !== null) {
            return $this->completed(
                'confirm_manual_submission',
                'submission_already_confirmed',
                [
                    'submission_confirmation_id' => $application
                        ->submissionConfirmation
                        ->getKey(),
                ],
            );
        }

        if (! $isDraft) {
            return $this->notApplicable(
                'confirm_manual_submission',
                'first_confirmation_requires_draft',
                'Only a draft application can receive its first submission confirmation.',
            );
        }

        if (! $readiness['ready']) {
            return $this->blocked(
                'confirm_manual_submission',
                $readiness['blockers'],
            );
        }

        return $this->available('confirm_manual_submission');
    }

    private function readDocument(JobApplication $application): array
    {
        try {
            $file = $this->documentReader->read($application);
        } catch (ValidationException $exception) {
            $blockers = [];

            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $blockers[] = [
                        'code' => 'document_unavailable',
                        'message' => $message,
                    ];
                }
            }

            return $this->blocked(
                'read_application_document',
                $blockers === []
                    ? [[
                        'code' => 'document_unavailable',
                        'message' => 'The application document is not available.',
                    ]]
                    : $blockers,
            );
        }

        return $this->available('read_application_document', [
            'document_source' => $file['document_source'],
            'generated_document_version_id' => $file['generated_document_version_id'],
            'source_resume_version_id' => $file['source_resume_version_id'],
            'filename' => $file['filename'],
            'mime_type' => $file['mime_type'],
            'file_size' => $file['file_size'],
            'checksum_sha256' => $file['checksum_sha256'],
        ]);
    }

    private function transitionStatus(
        JobApplication $application,
        array $readiness,
    ): array {
        if (! $this->statusWorkflow->supports($application->status)) {
            return $this->blocked(
                'transition_status',
                [[
                    'code' => 'unsupported_application_status',
                    'message' => 'The current application status is not supported by the workflow.',
                ]],
            );
        }

        $targets = [];

        foreach ($this->statusWorkflow->allowedTargets($application->status) as $target) {
            if ($application->status === 'draft' && $target === 'applied' && ! $readiness['ready']) {
                $targets[] = [
                    'status' => $target,
                    'availability' => self::STATUS_BLOCKED,
                    'reason_codes' => array_column($readiness['blockers'], 'code'),
                    'blockers' => $readiness['blockers'],
                ];

                continue;
            }

            $targets[] = [
                'status' => $target,
                'availability' => self::STATUS_AVAILABLE,
                'reason_codes' => [],
                'blockers' => [],
            ];
        }

        if ($targets === []) {
            return $this->notApplicable(
                'transition_status',
                'no_status_transitions_available',
                'The current application status has no outgoing transitions.',
                ['targets' => []],
            );
        }

        $availableTargets = array_values(array_filter(
            $targets,
            fn (array $target): bool => $target['availability'] === self::STATUS_AVAILABLE,
        ));

        return [
            'code' => 'transition_status',
            'status' => $availableTargets === []
                ? self::STATUS_BLOCKED
                : self::STATUS_AVAILABLE,
            'reason_codes' => $availableTargets === []
                ? ['all_status_targets_blocked']
                : [],
            'blockers' => [],
            'context' => [
                'targets' => $targets,
                'available_target_statuses' => array_column(
                    $availableTargets,
                    'status',
                ),
            ],
        ];
    }

    private function scheduleEvent(bool $isTerminal): array
    {
        if ($isTerminal) {
            return $this->notApplicable(
                'schedule_event',
                'terminal_application',
                'A terminal application cannot receive a new scheduled event.',
            );
        }

        return $this->available('schedule_event');
    }

    private function resolveScheduledEvent(array $plannedEventIds): array
    {
        if ($plannedEventIds === []) {
            return $this->notApplicable(
                'resolve_scheduled_event',
                'no_planned_scheduled_events',
                'There are no planned scheduled events to resolve.',
                [
                    'scheduled_event_ids' => [],
                    'target_statuses' => ['completed', 'cancelled'],
                ],
            );
        }

        return $this->available('resolve_scheduled_event', [
            'scheduled_event_ids' => $plannedEventIds,
            'target_statuses' => ['completed', 'cancelled'],
        ]);
    }

    private function rescheduleScheduledEvent(
        bool $isTerminal,
        array $plannedEventIds,
    ): array {
        if ($isTerminal) {
            return $this->notApplicable(
                'reschedule_scheduled_event',
                'terminal_application',
                'A terminal application cannot receive a replacement scheduled event.',
                ['scheduled_event_ids' => $plannedEventIds],
            );
        }

        if ($plannedEventIds === []) {
            return $this->notApplicable(
                'reschedule_scheduled_event',
                'no_planned_scheduled_events',
                'There are no planned scheduled events to reschedule.',
                ['scheduled_event_ids' => []],
            );
        }

        return $this->available('reschedule_scheduled_event', [
            'scheduled_event_ids' => $plannedEventIds,
        ]);
    }

    private function available(string $code, array $context = []): array
    {
        return $this->action(
            $code,
            self::STATUS_AVAILABLE,
            [],
            [],
            $context,
        );
    }

    private function blocked(string $code, array $blockers): array
    {
        return $this->action(
            $code,
            self::STATUS_BLOCKED,
            array_values(array_unique(array_column($blockers, 'code'))),
            array_values($blockers),
            [],
        );
    }

    private function completed(
        string $code,
        string $reasonCode,
        array $context = [],
    ): array {
        return $this->action(
            $code,
            self::STATUS_COMPLETED,
            [$reasonCode],
            [],
            $context,
        );
    }

    private function notApplicable(
        string $code,
        string $reasonCode,
        string $message,
        array $context = [],
    ): array {
        return $this->action(
            $code,
            self::STATUS_NOT_APPLICABLE,
            [$reasonCode],
            [[
                'code' => $reasonCode,
                'message' => $message,
            ]],
            $context,
        );
    }

    private function action(
        string $code,
        string $status,
        array $reasonCodes,
        array $blockers,
        array $context,
    ): array {
        return [
            'code' => $code,
            'status' => $status,
            'reason_codes' => $reasonCodes,
            'blockers' => $blockers,
            'context' => $context,
        ];
    }

    private function summary(array $actions): array
    {
        $summary = array_fill_keys([
            self::STATUS_AVAILABLE,
            self::STATUS_BLOCKED,
            self::STATUS_COMPLETED,
            self::STATUS_NOT_APPLICABLE,
        ], 0);

        foreach ($actions as $action) {
            $summary[$action['status']]++;
        }

        $summary['total'] = count($actions);

        return $summary;
    }
}
