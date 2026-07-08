<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProfileApplicationCompanyPerformanceBuilder
{
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
        int $minimumSampleSize,
    ): array {
        $companies = [];
        $eligible = [];
        $exclusions = [];

        foreach ($applications as $application) {
            $companyKey = $this->companyKey($application);
            $companies[$companyKey] ??= [
                'observed_names' => [],
                'submitted_total' => 0,
                'timelines' => [],
                'exclusions' => [],
            ];
            $companies[$companyKey]['submitted_total']++;
            $observedName = $this->observedName($application);

            if ($observedName !== null) {
                $companies[$companyKey]['observed_names'][] = $observedName;
            }

            $analysis = $this->timelineAnalyzer->analyze(
                $application,
                $referenceAt,
            );

            if ($analysis['reasons'] !== []) {
                foreach ($analysis['reasons'] as $reason) {
                    $exclusions[$reason] ??= [];
                    $exclusions[$reason][] = $application->getKey();
                    $companies[$companyKey]['exclusions'][$reason] ??= [];
                    $companies[$companyKey]['exclusions'][$reason][] = $application->getKey();
                }

                continue;
            }

            $eligible[] = $analysis['timeline'];
            $companies[$companyKey]['timelines'][] = $analysis['timeline'];
        }

        $rows = [];

        foreach ($companies as $companyKey => $company) {
            $timelines = collect($company['timelines']);
            $observedNames = $this->observedNames($company['observed_names']);
            $rows[] = [
                'company_key' => $companyKey,
                'display_name' => $observedNames[0] ?? 'Unknown company',
                'observed_names' => $observedNames,
                'submitted_in_range_total' => $company['submitted_total'],
                'eligible_total' => $timelines->count(),
                'excluded_total' => $company['submitted_total'] - $timelines->count(),
                'minimum_sample_size' => $minimumSampleSize,
                'meets_minimum_sample' => $timelines->count() >= $minimumSampleSize,
                'exclusions' => $this->exclusions($company['exclusions']),
                'performance' => $this->metricsSummarizer->summarize($timelines),
            ];
        }

        usort($rows, function (array $left, array $right): int {
            $comparison = $right['submitted_in_range_total']
                <=> $left['submitted_in_range_total'];

            return $comparison !== 0
                ? $comparison
                : strcmp($left['company_key'], $right['company_key']);
        });
        $eligible = collect($eligible);

        return [
            'profile_id' => $profile->getKey(),
            'reference_at' => $referenceAt->toISOString(),
            'range' => [
                'start_at' => $startAt->toISOString(),
                'end_at' => $endAt->toISOString(),
            ],
            'minimum_sample_size' => $minimumSampleSize,
            'methodology' => [
                'interpretation' => 'descriptive_not_causal',
                'grouping' => 'normalized_application_company_name_snapshot',
                'rate_denominator' => 'eligible_submitted_applications_in_company',
                'insufficient_samples_are_returned_not_hidden' => true,
            ],
            'population' => [
                'submitted_in_range_total' => $applications->count(),
                'eligible_total' => $eligible->count(),
                'excluded_total' => $applications->count() - $eligible->count(),
                'companies_total' => count($rows),
                'companies_meeting_minimum_sample' => collect($rows)
                    ->where('meets_minimum_sample', true)
                    ->count(),
            ],
            'exclusions' => $this->exclusions($exclusions),
            'totals' => $this->metricsSummarizer->summarize($eligible),
            'companies' => $rows,
        ];
    }

    private function companyKey(JobApplication $application): string
    {
        $name = $this->observedName($application);

        return $name === null
            ? 'unknown'
            : Str::lower($name);
    }

    private function observedName(JobApplication $application): ?string
    {
        if (! is_string($application->company_name)) {
            return null;
        }

        $name = Str::squish($application->company_name);

        return $name === '' ? null : $name;
    }

    private function observedNames(array $names): array
    {
        $names = array_values(array_unique($names));
        usort($names, function (string $left, string $right): int {
            $comparison = strcasecmp($left, $right);

            return $comparison !== 0
                ? $comparison
                : strcmp($left, $right);
        });

        return $names;
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
