<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class JobApplicationLifecycleTimelineAnalyzer
{
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

    public function exclusionOrder(): array
    {
        return self::EXCLUSION_ORDER;
    }

    public function analyze(
        JobApplication $application,
        CarbonImmutable $referenceAt,
    ): array {
        $reasons = [];
        $appliedAt = $application->applied_at?->toImmutable();

        if ($appliedAt === null) {
            return [
                'reasons' => ['missing_applied_at'],
                'timeline' => null,
            ];
        }

        $history = $this->normalizedHistory($application, $appliedAt);

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

        if ($first->changed_at->getTimestamp() !== $appliedAt->getTimestamp()) {
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
                ? $this->timeline(
                    $application,
                    $history,
                    $appliedAt,
                    $referenceAt,
                )
                : null,
        ];
    }

    private function normalizedHistory(
        JobApplication $application,
        CarbonImmutable $appliedAt,
    ): Collection {
        $history = $application->statusHistory->values();
        $first = $history->first();

        if (
            $first !== null
            && $first->from_status === null
            && $first->status === 'draft'
            && ! $first->changed_at->gt($appliedAt)
        ) {
            return $history->slice(1)->values();
        }

        return $history;
    }

    private function timeline(
        JobApplication $application,
        Collection $history,
        CarbonImmutable $appliedAt,
        CarbonImmutable $referenceAt,
    ): array {
        $milestones = ['applied' => $appliedAt];
        $transitions = [];
        $completedDurations = [];
        $openDurations = [];
        $currentStatus = 'applied';
        $enteredAt = $appliedAt;

        foreach ($history as $index => $entry) {
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

    private function orderedReasons(array $reasons): array
    {
        $reasons = array_values(array_unique($reasons));
        $priority = array_flip(self::EXCLUSION_ORDER);

        usort(
            $reasons,
            fn (string $left, string $right): int => ($priority[$left] ?? PHP_INT_MAX)
                <=> ($priority[$right] ?? PHP_INT_MAX),
        );

        return $reasons;
    }

    private function hoursBetween(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): float {
        return round(($to->getTimestamp() - $from->getTimestamp()) / 3600, 6);
    }
}
