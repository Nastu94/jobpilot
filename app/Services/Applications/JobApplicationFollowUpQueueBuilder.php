<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class JobApplicationFollowUpQueueBuilder
{
    private const TERMINAL_STATUSES = [
        'hired',
        'rejected',
        'withdrawn',
    ];

    private const UNSCHEDULED_STATUS_PRIORITY = [
        'offer' => 0,
        'interview' => 1,
        'assessment' => 2,
        'screening' => 3,
        'applied' => 4,
        'draft' => 5,
    ];

    public function __construct(
        private readonly JobApplicationFollowUpContextBuilder $contextBuilder,
    ) {
    }

    public function build(
        Profile $profile,
        CarbonImmutable $referenceAt,
        int $upcomingDays,
        int $limitPerBucket,
    ): array {
        $applications = JobApplication::query()
            ->where('profile_id', $profile->getKey())
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->with([
                'trackingHistory',
                'scheduledEvents' => fn ($query) => $query
                    ->where('status', 'planned')
                    ->orderBy('starts_at')
                    ->orderBy('id'),
            ])
            ->get();

        $classified = [
            'overdue' => collect(),
            'today' => collect(),
            'upcoming' => collect(),
            'later' => collect(),
            'unscheduled' => collect(),
        ];

        foreach ($applications as $application) {
            $followUp = $this->contextBuilder->build(
                $application,
                $referenceAt,
                $upcomingDays,
            );
            $bucket = $followUp['urgency'];

            $classified[$bucket]->push(
                $this->item($application, $followUp),
            );
        }

        $classified['overdue'] = $this->sortScheduled($classified['overdue']);
        $classified['today'] = $this->sortScheduled($classified['today']);
        $classified['upcoming'] = $this->sortScheduled($classified['upcoming']);
        $classified['later'] = $this->sortScheduled($classified['later']);
        $classified['unscheduled'] = $this->sortUnscheduled($classified['unscheduled']);

        $summary = [];
        $buckets = [];

        foreach ($classified as $name => $items) {
            $summary[$name] = [
                'total' => $items->count(),
                'returned' => min($items->count(), $limitPerBucket),
            ];
            $buckets[$name] = $items
                ->take($limitPerBucket)
                ->values()
                ->all();
        }

        return [
            'profile_id' => $profile->getKey(),
            'reference_at' => $referenceAt->toISOString(),
            'upcoming_days' => $upcomingDays,
            'limit_per_bucket' => $limitPerBucket,
            'active_total' => $applications->count(),
            'summary' => $summary,
            'buckets' => $buckets,
        ];
    }

    private function item(
        JobApplication $application,
        array $followUp,
    ): array {
        $latestTrackingChange = $application->trackingHistory->last()?->changed_at;

        return [
            'application_id' => $application->getKey(),
            'status' => $application->status,
            'job_title' => $application->job_title,
            'company_name' => $application->company_name,
            'application_channel' => $application->application_channel,
            'external_reference' => $application->external_reference,
            'applied_at' => $application->applied_at?->toISOString(),
            'next_action_at' => $followUp['next_action_at'],
            'follow_up_at' => $followUp['follow_up_at'],
            'follow_up_source' => $followUp['follow_up_source'],
            'scheduled_event' => $followUp['scheduled_event'],
            'latest_tracking_change_at' => $latestTrackingChange?->toISOString(),
            'urgency' => $followUp['urgency'],
            'reason_code' => $followUp['reason_code'],
            'days_from_reference' => $followUp['days_from_reference'],
        ];
    }

    private function sortScheduled(Collection $items): Collection
    {
        return $items->sort(function (array $left, array $right): int {
            $dateComparison = strcmp(
                (string) $left['follow_up_at'],
                (string) $right['follow_up_at'],
            );

            return $dateComparison !== 0
                ? $dateComparison
                : $left['application_id'] <=> $right['application_id'];
        })->values();
    }

    private function sortUnscheduled(Collection $items): Collection
    {
        return $items->sort(function (array $left, array $right): int {
            $statusComparison = $this->statusPriority($left['status'])
                <=> $this->statusPriority($right['status']);

            if ($statusComparison !== 0) {
                return $statusComparison;
            }

            $appliedComparison = strcmp(
                (string) $right['applied_at'],
                (string) $left['applied_at'],
            );

            return $appliedComparison !== 0
                ? $appliedComparison
                : $right['application_id'] <=> $left['application_id'];
        })->values();
    }

    private function statusPriority(string $status): int
    {
        return self::UNSCHEDULED_STATUS_PRIORITY[$status] ?? 99;
    }
}
