<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ProfileApplicationPortfolioDashboardBuilder
{
    private const PIPELINE_GROUPS = [
        'pre_submission' => ['draft'],
        'in_progress' => [
            'applied',
            'screening',
            'assessment',
            'interview',
            'offer',
        ],
        'outcomes' => [
            'hired',
            'rejected',
            'withdrawn',
        ],
    ];

    private const FOLLOW_UP_URGENCIES = [
        'overdue',
        'today',
        'upcoming',
        'later',
        'unscheduled',
    ];

    private const PRIORITY_SCORE = [
        'integrity_invalid' => 0,
        'integrity_warning' => 1,
        'follow_up_overdue' => 2,
        'follow_up_today' => 3,
        'follow_up_upcoming' => 4,
        'follow_up_unscheduled' => 5,
    ];

    public function __construct(
        private readonly JobApplicationIntegrityAuditor $integrityAuditor,
        private readonly JobApplicationFollowUpContextBuilder $followUpBuilder,
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function build(
        Profile $profile,
        Collection $applications,
        CarbonImmutable $referenceAt,
        int $upcomingDays,
        int $priorityLimit,
    ): array {
        $records = $applications
            ->map(fn (JobApplication $application): array => $this->record(
                $application,
                $referenceAt,
                $upcomingDays,
            ))
            ->values();
        $priorityQueue = $records
            ->filter(fn (array $record): bool => $record['priority'] !== null)
            ->sort(fn (array $left, array $right): int => $this->comparePriority(
                $left,
                $right,
            ))
            ->take($priorityLimit)
            ->values();

        return [
            'profile_id' => $profile->getKey(),
            'reference_at' => $referenceAt->toISOString(),
            'upcoming_days' => $upcomingDays,
            'priority_limit' => $priorityLimit,
            'summary' => $this->summary($records, $priorityQueue),
            'pipeline' => $this->pipeline($records),
            'integrity' => $this->integrity($records),
            'follow_up' => $this->followUp($records),
            'priority_queue' => $priorityQueue
                ->map(fn (array $record): array => $this->priorityItem($record))
                ->all(),
        ];
    }

    private function record(
        JobApplication $application,
        CarbonImmutable $referenceAt,
        int $upcomingDays,
    ): array {
        $integrity = $this->integrityAuditor->audit($application);
        $terminal = $this->statusWorkflow->isTerminal($application->status);
        $followUp = $terminal
            ? null
            : $this->followUpBuilder->build(
                $application,
                $referenceAt,
                $upcomingDays,
            );

        return [
            'application' => $application,
            'terminal' => $terminal,
            'integrity' => $integrity,
            'follow_up' => $followUp,
            'priority' => $this->priority($integrity, $followUp),
        ];
    }

    private function priority(array $integrity, ?array $followUp): ?array
    {
        $signals = [];

        if ($integrity['integrity_status'] === 'invalid') {
            $signals[] = 'integrity_invalid';
        } elseif ($integrity['integrity_status'] === 'warning') {
            $signals[] = 'integrity_warning';
        }

        if (
            $followUp !== null
            && in_array(
                $followUp['urgency'],
                ['overdue', 'today', 'upcoming', 'unscheduled'],
                true,
            )
        ) {
            $signals[] = 'follow_up_'.$followUp['urgency'];
        }

        if ($signals === []) {
            return null;
        }

        usort(
            $signals,
            fn (string $left, string $right): int => self::PRIORITY_SCORE[$left]
                <=> self::PRIORITY_SCORE[$right],
        );

        return [
            'primary_signal' => $signals[0],
            'score' => self::PRIORITY_SCORE[$signals[0]],
            'signals' => $signals,
        ];
    }

    private function summary(
        Collection $records,
        Collection $priorityQueue,
    ): array {
        $active = $records->where('terminal', false)->count();
        $terminal = $records->count() - $active;

        return [
            'applications_total' => $records->count(),
            'active_total' => $active,
            'terminal_total' => $terminal,
            'attention_total' => $records
                ->filter(fn (array $record): bool => $record['priority'] !== null)
                ->count(),
            'priority_returned' => $priorityQueue->count(),
            'submission_confirmed_total' => $records
                ->filter(fn (array $record): bool => $record['application']
                    ->submissionConfirmation !== null)
                ->count(),
            'planned_events_total' => $records->sum(
                fn (array $record): int => $record['application']
                    ->scheduledEvents
                    ->where('status', 'planned')
                    ->count(),
            ),
        ];
    }

    private function pipeline(Collection $records): array
    {
        $knownStatuses = $this->statusWorkflow->statuses();
        $byStatus = array_fill_keys($knownStatuses, 0);
        $unknownStatuses = [];

        foreach ($records as $record) {
            $status = $record['application']->status;

            if (! array_key_exists($status, $byStatus)) {
                $byStatus[$status] = 0;
                $unknownStatuses[] = $status;
            }

            $byStatus[$status]++;
        }

        $unknownStatuses = array_values(array_unique($unknownStatuses));
        sort($unknownStatuses);
        $orderedByStatus = [];

        foreach ($knownStatuses as $status) {
            $orderedByStatus[$status] = $byStatus[$status];
        }

        foreach ($unknownStatuses as $status) {
            $orderedByStatus[$status] = $byStatus[$status];
        }

        $groups = [];

        foreach (self::PIPELINE_GROUPS as $group => $statuses) {
            $groups[$group] = [
                'total' => array_sum(array_map(
                    fn (string $status): int => $byStatus[$status] ?? 0,
                    $statuses,
                )),
                'statuses' => array_combine(
                    $statuses,
                    array_map(
                        fn (string $status): int => $byStatus[$status] ?? 0,
                        $statuses,
                    ),
                ),
            ];
        }

        $groups['unknown'] = [
            'total' => array_sum(array_map(
                fn (string $status): int => $byStatus[$status],
                $unknownStatuses,
            )),
            'statuses' => array_combine(
                $unknownStatuses,
                array_map(
                    fn (string $status): int => $byStatus[$status],
                    $unknownStatuses,
                ),
            ) ?: [],
        ];

        return [
            'by_status' => $orderedByStatus,
            'groups' => $groups,
        ];
    }

    private function integrity(Collection $records): array
    {
        $byStatus = [
            'healthy' => 0,
            'warning' => 0,
            'invalid' => 0,
        ];
        $issuesByCode = [];
        $errors = 0;
        $warnings = 0;

        foreach ($records as $record) {
            $audit = $record['integrity'];
            $byStatus[$audit['integrity_status']]++;
            $errors += $audit['summary']['errors'];
            $warnings += $audit['summary']['warnings'];

            foreach ($audit['issues'] as $issue) {
                $code = $issue['code'];

                if (! isset($issuesByCode[$code])) {
                    $issuesByCode[$code] = [
                        'code' => $code,
                        'severity' => $issue['severity'],
                        'total' => 0,
                        'application_ids' => [],
                    ];
                }

                $issuesByCode[$code]['total']++;
                $issuesByCode[$code]['application_ids'][] = $record['application']->getKey();
            }
        }

        foreach ($issuesByCode as &$issue) {
            $issue['application_ids'] = array_values(array_unique(
                $issue['application_ids'],
            ));
            sort($issue['application_ids']);
        }
        unset($issue);

        usort($issuesByCode, function (array $left, array $right): int {
            $comparison = $right['total'] <=> $left['total'];

            return $comparison !== 0
                ? $comparison
                : strcmp($left['code'], $right['code']);
        });

        return [
            'by_status' => $byStatus,
            'errors_total' => $errors,
            'warnings_total' => $warnings,
            'issues_total' => $errors + $warnings,
            'issues_by_code' => array_values($issuesByCode),
        ];
    }

    private function followUp(Collection $records): array
    {
        $byUrgency = array_fill_keys(self::FOLLOW_UP_URGENCIES, 0);
        $bySource = [
            'scheduled_event' => 0,
            'next_action' => 0,
            'none' => 0,
        ];

        foreach ($records as $record) {
            if ($record['follow_up'] === null) {
                continue;
            }

            $followUp = $record['follow_up'];
            $byUrgency[$followUp['urgency']]++;
            $source = $followUp['follow_up_source'] ?? 'none';
            $bySource[$source]++;
        }

        return [
            'active_total' => array_sum($byUrgency),
            'by_urgency' => $byUrgency,
            'by_source' => $bySource,
        ];
    }

    private function comparePriority(array $left, array $right): int
    {
        $comparison = $left['priority']['score']
            <=> $right['priority']['score'];

        if ($comparison !== 0) {
            return $comparison;
        }

        $leftFollowUp = $left['follow_up']['follow_up_at'] ?? null;
        $rightFollowUp = $right['follow_up']['follow_up_at'] ?? null;

        if ($leftFollowUp !== $rightFollowUp) {
            if ($leftFollowUp === null) {
                return 1;
            }

            if ($rightFollowUp === null) {
                return -1;
            }

            $comparison = strcmp($leftFollowUp, $rightFollowUp);

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return $left['application']->getKey()
            <=> $right['application']->getKey();
    }

    private function priorityItem(array $record): array
    {
        $application = $record['application'];
        $integrity = $record['integrity'];
        $followUp = $record['follow_up'];

        return [
            'application_id' => $application->getKey(),
            'application_status' => $application->status,
            'job_title' => $application->job_title,
            'company_name' => $application->company_name,
            'primary_signal' => $record['priority']['primary_signal'],
            'signals' => $record['priority']['signals'],
            'integrity_status' => $integrity['integrity_status'],
            'integrity_issue_codes' => array_values(array_unique(array_column(
                $integrity['issues'],
                'code',
            ))),
            'follow_up_at' => $followUp['follow_up_at'] ?? null,
            'follow_up_source' => $followUp['follow_up_source'] ?? null,
            'follow_up_urgency' => $followUp['urgency'] ?? null,
            'follow_up_reason_code' => $followUp['reason_code'] ?? null,
            'scheduled_event' => $followUp['scheduled_event'] ?? null,
        ];
    }
}
