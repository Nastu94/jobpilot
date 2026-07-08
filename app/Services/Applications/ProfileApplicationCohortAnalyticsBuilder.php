<?php

namespace App\Services\Applications;

use App\Models\Profile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ProfileApplicationCohortAnalyticsBuilder
{
    public const GRANULARITIES = [
        'week',
        'month',
    ];

    private const MILESTONES = [
        'screening',
        'assessment',
        'interview',
        'offer',
        'hired',
    ];

    public function __construct(
        private readonly JobApplicationLifecycleTimelineAnalyzer $timelineAnalyzer,
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function build(
        Profile $profile,
        Collection $applications,
        CarbonImmutable $referenceAt,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        string $granularity,
    ): array {
        $eligible = [];
        $exclusions = [];

        foreach ($applications as $application) {
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
        $buckets = collect($this->periods(
            $startAt,
            $endAt,
            $granularity,
        ))->map(function (array $period) use ($eligible, $granularity): array {
            $cohort = $eligible
                ->filter(fn (array $timeline): bool => $this->periodKey(
                    $timeline['applied_at'],
                    $granularity,
                ) === $period['period_key'])
                ->values();

            return array_merge(
                $period,
                $this->summary($cohort),
            );
        })->values();

        return [
            'profile_id' => $profile->getKey(),
            'reference_at' => $referenceAt->toISOString(),
            'range' => [
                'start_at' => $startAt->toISOString(),
                'end_at' => $endAt->toISOString(),
                'granularity' => $granularity,
                'periods_total' => $buckets->count(),
            ],
            'population' => [
                'submitted_in_range_total' => $applications->count(),
                'eligible_total' => $eligible->count(),
                'excluded_total' => $applications->count() - $eligible->count(),
            ],
            'exclusions' => $this->exclusions($exclusions),
            'totals' => $this->summary($eligible),
            'buckets' => $buckets->all(),
        ];
    }

    private function summary(Collection $timelines): array
    {
        $submitted = $timelines->count();
        $terminal = $timelines
            ->filter(fn (array $timeline): bool => $this->statusWorkflow
                ->isTerminal($timeline['current_status']))
            ->count();
        $milestones = [];

        foreach (self::MILESTONES as $status) {
            $durations = [];

            foreach ($timelines as $timeline) {
                if (! isset($timeline['milestones'][$status])) {
                    continue;
                }

                $durations[] = $this->hoursBetween(
                    $timeline['applied_at'],
                    $timeline['milestones'][$status],
                );
            }

            $milestones[$status] = [
                'reached_total' => count($durations),
                'conversion_percent' => $this->rate(
                    count($durations),
                    $submitted,
                ),
                'time_from_application' => $this->statistics($durations),
            ];
        }

        $outcomes = [];

        foreach ($this->statusWorkflow->terminalStatuses() as $status) {
            $total = $timelines->where('current_status', $status)->count();
            $outcomes[$status] = [
                'total' => $total,
                'rate_from_submitted_percent' => $this->rate(
                    $total,
                    $submitted,
                ),
                'rate_from_terminal_percent' => $this->rate(
                    $total,
                    $terminal,
                ),
            ];
        }

        return [
            'submitted_total' => $submitted,
            'active_total' => $submitted - $terminal,
            'terminal_total' => $terminal,
            'milestones' => $milestones,
            'outcomes' => $outcomes,
        ];
    }

    private function periods(
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        string $granularity,
    ): array {
        $periods = [];
        $cursor = $granularity === 'week'
            ? $startAt->startOfWeek(CarbonInterface::MONDAY)
            : $startAt->startOfMonth();

        while ($cursor->lte($endAt)) {
            $naturalEnd = $granularity === 'week'
                ? $cursor->endOfWeek(CarbonInterface::SUNDAY)
                : $cursor->endOfMonth();
            $periods[] = [
                'period_key' => $this->periodKey($cursor, $granularity),
                'starts_at' => ($cursor->gt($startAt) ? $cursor : $startAt)
                    ->toISOString(),
                'ends_at' => ($naturalEnd->lt($endAt) ? $naturalEnd : $endAt)
                    ->toISOString(),
            ];
            $cursor = $granularity === 'week'
                ? $cursor->addWeek()
                : $cursor->addMonth();
        }

        return $periods;
    }

    private function periodKey(
        CarbonImmutable $date,
        string $granularity,
    ): string {
        return $granularity === 'week'
            ? $date->format('o-\WW')
            : $date->format('Y-m');
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
