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

        $startOfDay = $referenceAt->startOfDay();
        $endOfDay = $referenceAt->endOfDay();
        $upcomingEnd = $endOfDay->addDays($upcomingDays);

        $classified = [
            'overdue' => collect(),
            'today' => collect(),
            'upcoming' => collect(),
            'later' => collect(),
            'unscheduled' => collect(),
        ];

        foreach ($applications as $application) {
            $followUp = $this->followUpContext($application);
            $bucket = $this->bucketFor(
                $followUp['follow_up_at'],
                $startOfDay,
                $endOfDay,
                $upcomingEnd,
            );

            $classified[$bucket]->push(
                $this->item(
                    $application,
                    $followUp,
                    $bucket,
                    $startOfDay,
                ),
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

    private function followUpContext(JobApplication $application): array
    {
        $nextActionAt = $application->next_action_at?->toImmutable();
        $scheduledEvent = $application->scheduledEvents->first();
        $scheduledAt = $scheduledEvent?->starts_at?->toImmutable();

        if ($nextActionAt === null && $scheduledAt === null) {
            return [
                'follow_up_at' => null,
                'follow_up_source' => null,
                'scheduled_event' => null,
            ];
        }

        if (
            $scheduledAt !== null
            && ($nextActionAt === null || $scheduledAt->lte($nextActionAt))
        ) {
            return [
                'follow_up_at' => $scheduledAt,
                'follow_up_source' => 'scheduled_event',
                'scheduled_event' => $scheduledEvent,
            ];
        }

        return [
            'follow_up_at' => $nextActionAt,
            'follow_up_source' => 'next_action',
            'scheduled_event' => $scheduledEvent,
        ];
    }

    private function bucketFor(
        ?CarbonImmutable $followUpAt,
        CarbonImmutable $startOfDay,
        CarbonImmutable $endOfDay,
        CarbonImmutable $upcomingEnd,
    ): string {
        if ($followUpAt === null) {
            return 'unscheduled';
        }

        if ($followUpAt->lt($startOfDay)) {
            return 'overdue';
        }

        if ($followUpAt->lte($endOfDay)) {
            return 'today';
        }

        if ($followUpAt->lte($upcomingEnd)) {
            return 'upcoming';
        }

        return 'later';
    }

    private function item(
        JobApplication $application,
        array $followUp,
        string $bucket,
        CarbonImmutable $startOfDay,
    ): array {
        $nextActionAt = $application->next_action_at?->toImmutable();
        $followUpAt = $followUp['follow_up_at'];
        $scheduledEvent = $followUp['scheduled_event'];
        $latestTrackingChange = $application->trackingHistory->last()?->changed_at;

        return [
            'application_id' => $application->getKey(),
            'status' => $application->status,
            'job_title' => $application->job_title,
            'company_name' => $application->company_name,
            'application_channel' => $application->application_channel,
            'external_reference' => $application->external_reference,
            'applied_at' => $application->applied_at?->toISOString(),
            'next_action_at' => $nextActionAt?->toISOString(),
            'follow_up_at' => $followUpAt?->toISOString(),
            'follow_up_source' => $followUp['follow_up_source'],
            'scheduled_event' => $scheduledEvent === null
                ? null
                : [
                    'id' => $scheduledEvent->getKey(),
                    'event_type' => $scheduledEvent->event_type,
                    'title' => $scheduledEvent->title,
                    'starts_at' => $scheduledEvent->starts_at->toISOString(),
                    'ends_at' => $scheduledEvent->ends_at?->toISOString(),
                    'location' => $scheduledEvent->location,
                ],
            'latest_tracking_change_at' => $latestTrackingChange?->toISOString(),
            'urgency' => $bucket,
            'reason_code' => $this->reasonCode(
                $bucket,
                $followUp['follow_up_source'],
            ),
            'days_from_reference' => $followUpAt === null
                ? null
                : (int) $startOfDay->diffInDays($followUpAt->startOfDay(), false),
        ];
    }

    private function reasonCode(string $bucket, ?string $source): string
    {
        if ($source === 'scheduled_event') {
            return match ($bucket) {
                'overdue' => 'scheduled_event_overdue',
                'today' => 'scheduled_event_due_today',
                'upcoming' => 'scheduled_event_upcoming',
                'later' => 'scheduled_event_scheduled_later',
                'unscheduled' => 'active_application_without_follow_up',
            };
        }

        return match ($bucket) {
            'overdue' => 'next_action_overdue',
            'today' => 'next_action_due_today',
            'upcoming' => 'next_action_upcoming',
            'later' => 'next_action_scheduled_later',
            'unscheduled' => 'active_application_without_next_action',
        };
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
