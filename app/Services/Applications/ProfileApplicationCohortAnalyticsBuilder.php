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

    public function __construct(
        private readonly JobApplicationLifecycleTimelineAnalyzer $timelineAnalyzer,
        private readonly JobApplicationLifecycleMetricsSummarizer $metricsSummarizer,
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
                $this->metricsSummarizer->summarize($cohort),
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
            'totals' => $this->metricsSummarizer->summarize($eligible),
            'buckets' => $buckets->all(),
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
}
