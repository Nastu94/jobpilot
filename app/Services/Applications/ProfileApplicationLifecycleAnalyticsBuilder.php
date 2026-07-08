<?php

namespace App\Services\Applications;

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

    public function __construct(
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
        private readonly JobApplicationLifecycleTimelineAnalyzer $timelineAnalyzer,
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
            $analysis = $this->timelineAnalyzer->analyze(
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

        foreach ($this->timelineAnalyzer->exclusionOrder() as $reason) {
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
