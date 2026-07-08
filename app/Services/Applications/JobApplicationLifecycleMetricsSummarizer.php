<?php

namespace App\Services\Applications;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class JobApplicationLifecycleMetricsSummarizer
{
    private const MILESTONES = [
        'screening',
        'assessment',
        'interview',
        'offer',
        'hired',
    ];

    public function __construct(
        private readonly JobApplicationStatusWorkflow $statusWorkflow,
    ) {
    }

    public function summarize(Collection $timelines): array
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
