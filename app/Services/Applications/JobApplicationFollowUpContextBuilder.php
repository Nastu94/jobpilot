<?php

namespace App\Services\Applications;

use App\Models\JobApplication;
use App\Models\JobApplicationScheduledEvent;
use Carbon\CarbonImmutable;

class JobApplicationFollowUpContextBuilder
{
    public function build(
        JobApplication $application,
        CarbonImmutable $referenceAt,
        int $upcomingDays,
    ): array {
        $startOfDay = $referenceAt->startOfDay();
        $endOfDay = $referenceAt->endOfDay();
        $upcomingEnd = $endOfDay->addDays($upcomingDays);
        $nextActionAt = $application->next_action_at?->toImmutable();
        $scheduledEvent = $this->nextPlannedEvent($application);
        $scheduledAt = $scheduledEvent?->starts_at?->toImmutable();

        if (
            $scheduledAt !== null
            && ($nextActionAt === null || $scheduledAt->lte($nextActionAt))
        ) {
            $followUpAt = $scheduledAt;
            $source = 'scheduled_event';
        } else {
            $followUpAt = $nextActionAt;
            $source = $nextActionAt === null ? null : 'next_action';
        }

        $urgency = $this->urgency(
            $followUpAt,
            $startOfDay,
            $endOfDay,
            $upcomingEnd,
        );

        return [
            'reference_at' => $referenceAt->toISOString(),
            'upcoming_days' => $upcomingDays,
            'next_action_at' => $nextActionAt?->toISOString(),
            'follow_up_at' => $followUpAt?->toISOString(),
            'follow_up_source' => $source,
            'scheduled_event' => $this->scheduledEvent($scheduledEvent),
            'urgency' => $urgency,
            'reason_code' => $this->reasonCode($urgency, $source),
            'days_from_reference' => $followUpAt === null
                ? null
                : (int) $startOfDay->diffInDays(
                    $followUpAt->startOfDay(),
                    false,
                ),
        ];
    }

    private function nextPlannedEvent(
        JobApplication $application,
    ): ?JobApplicationScheduledEvent {
        if ($application->relationLoaded('scheduledEvents')) {
            return $application->scheduledEvents
                ->firstWhere('status', 'planned');
        }

        return $application->scheduledEvents()
            ->where('status', 'planned')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();
    }

    private function scheduledEvent(
        ?JobApplicationScheduledEvent $scheduledEvent,
    ): ?array {
        if ($scheduledEvent === null) {
            return null;
        }

        return [
            'id' => $scheduledEvent->getKey(),
            'event_type' => $scheduledEvent->event_type,
            'title' => $scheduledEvent->title,
            'starts_at' => $scheduledEvent->starts_at->toISOString(),
            'ends_at' => $scheduledEvent->ends_at?->toISOString(),
            'location' => $scheduledEvent->location,
        ];
    }

    private function urgency(
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

    private function reasonCode(string $urgency, ?string $source): string
    {
        if ($source === 'scheduled_event') {
            return match ($urgency) {
                'overdue' => 'scheduled_event_overdue',
                'today' => 'scheduled_event_due_today',
                'upcoming' => 'scheduled_event_upcoming',
                'later' => 'scheduled_event_scheduled_later',
                'unscheduled' => 'active_application_without_follow_up',
            };
        }

        return match ($urgency) {
            'overdue' => 'next_action_overdue',
            'today' => 'next_action_due_today',
            'upcoming' => 'next_action_upcoming',
            'later' => 'next_action_scheduled_later',
            'unscheduled' => 'active_application_without_next_action',
        };
    }
}
