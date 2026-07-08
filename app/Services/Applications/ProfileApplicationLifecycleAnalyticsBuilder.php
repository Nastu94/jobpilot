<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ProfileApplicationLifecycleAnalyticsBuilder
{
    private const MILESTONES = [
        'applied',
        'screening',
        'assessment',
        'interview',
        'offer',
        'hired',
    ];

    private const DURATION_STATUSES = [
        'applied',
        'screening',
        'assessment',
        'interview',
        'offer',
    ];

    private const EXCLUSION_ORDER = [
        'applied_after_reference',
        'unsupported_current_status',
        'submitted_application_in_draft',
        'missing_status_history',
        'invalid_initial_application_transition',
        'applied_at_history_mismatch',
        'history_after_reference',
        'history_non_monotonic',
        'history_chain_broken',
        'unsupported_history_status',
        'invalid_status_transition',
        'current_status_history_mismatch',
    ];

    public function __construct(
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function build(
        Profile $profile,
        Collection $applications,
        CarbonImmutable $referenceAt,
    ): array {
        $eligible = [];
        $exclusions = [];
        $submittedTotal = 0;
        $draftsTotal = 0;
        $notSubmittedTotal = 0;
        $nonSubmittedTerminalTotal = 0;

        foreach ($applications as $application) {
            if ($application->status === 'draft') {
                $draftsTotal++;
            }

            if ($application->applied_at === null) {
                $notSubmittedTotal++;

                if ($this->statusWorkflow->isTerminal($application->status)) {
                    $nonSubmittedTerminalTotal++;
                }

                continue;
            }

            $submittedTotal++;
            $analysis = $this->analyzeApplication(
                $application,
                $referenceAt,
            );

            if ($analysis['reasons'] !== []) {
                foreach ($analysis['reasons'] as $reason) {
                    $exclusions[$reason] ??= [];
                    $exclusions[$reason][] = $application->getKey();
                }

                continue;
            }

            $eligible[] = $analysis['timeline'];
        }

        $eligible = collect($eligible);
        $activeEligible = $eligible
            ->filter(fn (array $timeline): bool => ! $this->statusWorkflow
                ->isTerminal($timeline['current_status']))
            ->count();
        $terminalEligible = $eligible->count() - $activeEligible;

        return [
            'profile_id' => $profile->getKey(),
            'reference_at' => $referenceAt->toISOString(),
            'population' => [
                'applications_total' => $applications->count(),
                'drafts_total' => $draftsTotal,
                'not_submitted_total' => $notSubmittedTotal,
                'non_submitted_terminal_total' => $nonSubmittedTerminalTotal,
                'submitted_total' => $submittedTotal,
                'eligible_total' => $eligible->count(),
                'excluded_total' => $submittedTotal - $eligible->count(),
                'active_eligible_total' => $activeEligible,
                'terminal_eligible_total' => $terminalEligible,
            ],
            'exclusions' => $this->exclusions($exclusions),
            'milestones' => $this->milestones($eligible),
            'outcomes' => $this->outcomes($eligible),
            'transitions' => $this->transitions($eligible),
            'stage_durations' => $this->stageDurations($eligible),
        ];
    }

    private function analyzeApplication(
        JobApplication $application,
        CarbonImmutable $referenceAt,
    ): array {
        $reasons = [];
        $appliedAt = $application->applied_at->toImmutable();
        $history = $application->statusHistory->values();

        if ($appliedAt->gt($referenceAt)) {
            $reasons[] = 'applied_after_reference';
        }

        if (! $this->statusWorkflow->supports($application->status)) {
            $reasons[] = 'unsupported_current_status';
        }

        if ($application->status === 'draft') {
            $reasons[] = 'submitted_application_in_draft';
        }

        if ($history->isEmpty()) {
            $reasons[] = 'missing_status_history';

            return [
                'reasons' => $this->orderedReasons($reasons),
                'timeline' => null,
            ];
        }

        $first = $history->first();

        if ($first->from_status !== 'draft' || $first->status !== 'applied') {
            $reasons[] = 'invalid_initial_application_transition';
        }

        if (
            $first->changed_at->getTimestamp()
            !== $appliedAt->getTimestamp()
        ) {
            $reasons[] = 'applied_at_history_mismatch';
        }

        $previousStatus = 'draft';
        $previousAt = null;

        foreach ($history as $entry) {
            $changedAt = $entry->changed_at->toImmutable();

            if ($changedAt->gt($referenceAt)) {
                $reasons[] = 'history_after_reference';
            }

            if ($previousAt !== null && $changedAt->lt($previousAt)) {
                $reasons[] = 'history_non_monotonic';
            }

            if ($entry->from_status !== $previousStatus) {
                $reasons[] = 'history_chain_broken';
            }

            if (
                ! $this->statusWorkflow->supports($entry->from_status)
                || ! $this->statusWorkflow->supports($entry->status)
            ) {
                $reasons[] = 'unsupported_history_status';
            } elseif (! $this->statusWorkflow->canTransition(
                $entry->from_status,
                $entry->status,
            )) {
                $reasons[] = 'invalid_status_transition';
            }

            $previousStatus = $entry->status;
            $previousAt = $changedAt;
        }

        if ($previousStatus !== $application->status) {
            $reasons[] = 'current_status_history_mismatch';
        }

        $reasons = $this->orderedReasons($reasons);

        return [
            'reasons' => $reasons,
            'timeline' => $reasons === []
                ? $this->timeline($application, $appliedAt, $referenceAt)
                : null,
        ];
    }

    private function timeline(
        JobApplication $application,
        CarbonImmutable $appliedAt,
        CarbonImmutable $referenceAt,
    ): array {
        $milestones = ['applied' => $appliedAt];
        $transitions = [];
        $completedDurations = [];
        $openDurations = [];
        $currentStatus = 'applied';
        $enteredAt = $appliedAt;

        foreach ($application->statusHistory as $index => $entry) {
            $changedAt = $entry->changed_at->toImmutable();
            $transitions[] = [
                'from_status' => $entry->from_status,
                'to_status' => $entry->status,
            ];

            if ($index === 0) {
                continue;
            }

            $completedDurations[$currentStatus] ??= [];
            $completedDurations[$currentStatus][] = $this->hoursBetween(
                $enteredAt,
                $changedAt,
            );
            $currentStatus = $entry->status;
            $enteredAt = $changedAt;
            $milestones[$currentStatus] ??= $changedAt;
        }

        if (! $this->statusWorkflow->isTerminal($currentStatus)) {
            $openDurations[$currentStatus] = [
                $this->hoursBetween($enteredAt, $referenceAt),
            ];
        }

        return [
            'application_id' => $application->getKey(),
            'current_status' => $currentStatus,
            'applied_at' => $appliedAt,
            'milestones' => $milestones,
            'transitions' => $transitions,
            'completed_durations' => $completedDurations,
            'open_durations' => $openDurations,
        ];
    }

    private function milestones(Collection $eligible): array
    {
        $denominator = $eligible->count();
        $result = [];

        foreach (self::MILESTONES as $status) {
            $durations = [];

            foreach ($eligible as $timeline) {
                if (! isset($timeline['milestones'][$status])) {
                    continue;
                }

                $durations[] = $this->hoursBetween(
                    $timeline['applied_at'],
                    $timeline['milestones'][$status],
                );
            }

            $result[] = [
                'status' => $status,
                'reached_total' => count($durations),
                'rate_from_eligible_submitted_percent' => $this->rate(
                    count($durations),
                    $denominator,
                ),
                'time_from_application' => $this->statistics($durations),
            ];
        }

        return $result;
    }

    private function outcomes(Collection $eligible): array
    {
        $terminalStatuses = $this->statusWorkflow->terminalStatuses();
        $terminalTotal = $eligible
            ->filter(fn (array $timeline): bool => in_array(
                $timeline['current_status'],
                $terminalStatuses,
                true,
            ))
            ->count();
        $byStatus = [];

        foreach ($terminalStatuses as $status) {
            $total = $eligible
                ->where('current_status', $status)
                ->count();
            $byStatus[$status] = [
                'total' => $total,
                'rate_from_terminal_percent' => $this->rate(
                    $total,
                    $terminalTotal,
                ),
                'rate_from_eligible_submitted_percent' => $this->rate(
                    $total,
                    $eligible->count(),
                ),
            ];
        }

        return [
            'terminal_total' => $terminalTotal,
            'pending_total' => $eligible->count() - $terminalTotal,
            'terminal_rate_percent' => $this->rate(
                $terminalTotal,
                $eligible->count(),
            ),
            'by_status' => $byStatus,
        ];
    }

    private function transitions(Collection $eligible): array
    {
        $routes = [];
        $total = 0;

        foreach ($eligible as $timeline) {
            foreach ($timeline['transitions'] as $transition) {
                $key = $transition['from_status'].'->'.$transition['to_status'];
                $routes[$key] ??= [
                    'from_status' => $transition['from_status'],
                    'to_status' => $transition['to_status'],
                    'total' => 0,
                ];
                $routes[$key]['total']++;
                $total++;
            }
        }

        usort($routes, function (array $left, array $right): int {
            $comparison = $right['total'] <=> $left['total'];

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strcmp($left['from_status'], $right['from_status']);

            return $comparison !== 0
                ? $comparison
                : strcmp($left['to_status'], $right['to_status']);
        });

        return [
            'events_total' => $total,
            'routes' => array_values($routes),
        ];
    }

    private function stageDurations(Collection $eligible): array
    {
        $result = [];

        foreach (self::DURATION_STATUSES as $status) {
            $completed = [];
            $open = [];

            foreach ($eligible as $timeline) {
                foreach (
                    $timeline['completed_durations'][$status] ?? []
                    as $duration
                ) {
                    $completed[] = $duration;
                }

                foreach (
                    $timeline['open_durations'][$status] ?? []
                    as $duration
                ) {
                    $open[] = $duration;
                }
            }

            $result[] = [
                'status' => $status,
                'completed_intervals' => $this->statistics($completed),
                'open_intervals' => $this->statistics($open),
            ];
        }

        return $result;
    }

    private function exclusions(array $exclusions): array
    {
        $result = [];

        foreach (self::EXCLUSION_ORDER as $reason) {
            if (! isset($exclusions[$reason])) {
                continue;
            }

            $applicationIds = array_values(array_unique($exclusions[$reason]));
            sort($applicationIds);
            $result[] = [
                'reason_code' => $reason,
                'total' => count($applicationIds),
                'application_ids' => $applicationIds,
            ];
        }

        return $result;
    }

    private function orderedReasons(array $reasons): array
    {
        $reasons = array_values(array_unique($reasons));
        $priority = array_flip(self::EXCLUSION_ORDER);

        usort(
            $reasons,
            fn (string $left, string $right): int => $priority[$left]
                <=> $priority[$right],
        );

        return $reasons;
    }

    private function statistics(array $values): array
    {
        if ($values === []) {
            return [
                'sample_count' => 0,
                'average_hours' => null,
                'median_hours' => null,
                'minimum_hours' => null,
                'maximum_hours' => null,
            ];
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;

        return [
            'sample_count' => $count,
            'average_hours' => round(array_sum($values) / $count, 2),
            'median_hours' => round($median, 2),
            'minimum_hours' => round($values[0], 2),
            'maximum_hours' => round($values[$count - 1], 2),
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator === 0
            ? null
            : round(($numerator / $denominator) * 100, 2);
    }

    private function hoursBetween(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): float {
        return round(($to->getTimestamp() - $from->getTimestamp()) / 3600, 6);
    }
}
