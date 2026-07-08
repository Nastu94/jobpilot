<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProfileApplicationSourcePerformanceBuilder
{
    public const DIMENSIONS = [
        'job_source',
        'application_channel',
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
        string $dimension,
    ): array {
        $groups = [];
        $eligible = [];
        $exclusions = [];

        foreach ($applications as $application) {
            $groupKey = $this->groupKey($application, $dimension);
            $groups[$groupKey] ??= [
                'submitted_total' => 0,
                'timelines' => [],
                'exclusions' => [],
            ];
            $groups[$groupKey]['submitted_total']++;
            $analysis = $this->timelineAnalyzer->analyze(
                $application,
                $referenceAt,
            );

            if ($analysis['reasons'] !== []) {
                foreach ($analysis['reasons'] as $reason) {
                    $exclusions[$reason] ??= [];
                    $exclusions[$reason][] = $application->getKey();
                    $groups[$groupKey]['exclusions'][$reason] ??= [];
                    $groups[$groupKey]['exclusions'][$reason][] = $application->getKey();
                }

                continue;
            }

            $eligible[] = $analysis['timeline'];
            $groups[$groupKey]['timelines'][] = $analysis['timeline'];
        }

        $groupRows = [];

        foreach ($groups as $groupKey => $group) {
            $timelines = collect($group['timelines']);
            $groupRows[] = [
                'group_key' => $groupKey,
                'submitted_in_range_total' => $group['submitted_total'],
                'eligible_total' => $timelines->count(),
                'excluded_total' => $group['submitted_total'] - $timelines->count(),
                'exclusions' => $this->exclusions($group['exclusions']),
                'performance' => $this->metricsSummarizer->summarize($timelines),
            ];
        }

        usort($groupRows, function (array $left, array $right): int {
            $comparison = $right['submitted_in_range_total']
                <=> $left['submitted_in_range_total'];

            return $comparison !== 0
                ? $comparison
                : strcmp($left['group_key'], $right['group_key']);
        });
        $eligible = collect($eligible);

        return [
            'profile_id' => $profile->getKey(),
            'reference_at' => $referenceAt->toISOString(),
            'range' => [
                'start_at' => $startAt->toISOString(),
                'end_at' => $endAt->toISOString(),
            ],
            'dimension' => $dimension,
            'methodology' => [
                'interpretation' => 'descriptive_not_causal',
                'rate_denominator' => 'eligible_submitted_applications_in_group',
                'value_source' => 'submission_snapshot_with_legacy_live_fallback',
                'group_key_normalization' => 'trim_squish_lowercase_unknown_for_blank',
            ],
            'population' => [
                'submitted_in_range_total' => $applications->count(),
                'eligible_total' => $eligible->count(),
                'excluded_total' => $applications->count() - $eligible->count(),
                'groups_total' => count($groupRows),
            ],
            'exclusions' => $this->exclusions($exclusions),
            'totals' => $this->metricsSummarizer->summarize($eligible),
            'groups' => $groupRows,
        ];
    }

    private function groupKey(
        JobApplication $application,
        string $dimension,
    ): string {
        $value = $this->dimensionValue($application, $dimension);

        if (! is_string($value)) {
            return 'unknown';
        }

        $value = Str::lower(Str::squish($value));

        return $value === '' ? 'unknown' : $value;
    }

    private function dimensionValue(
        JobApplication $application,
        string $dimension,
    ): ?string {
        if ($application->submitted_context_captured_at !== null) {
            return $dimension === 'job_source'
                ? $application->submitted_job_source
                : $application->submitted_application_channel;
        }

        return $dimension === 'job_source'
            ? $application->jobPosting?->source
            : $application->application_channel;
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
