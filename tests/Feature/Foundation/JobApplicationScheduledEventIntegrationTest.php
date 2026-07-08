<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationFollowUpQueue;
use App\Actions\Applications\BuildJobApplicationTimeline;
use App\Actions\Applications\ResolveJobApplicationScheduledEvent;
use App\Actions\Applications\ScheduleJobApplicationEvent;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\JobApplicationTimelineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobApplicationScheduledEventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-08 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_planned_event_schedules_an_application_without_next_action(): void
    {
        [$owner, $profile, $application] = $this->scenario();
        $event = $this->schedule($application, $owner, '2026-07-10 10:00:00');

        $queue = $this->queue($profile, $owner);
        $item = $queue['buckets']['upcoming'][0];

        $this->assertSame($application->id, $item['application_id']);
        $this->assertNull($item['next_action_at']);
        $this->assertSame('scheduled_event', $item['follow_up_source']);
        $this->assertSame(
            CarbonImmutable::parse('2026-07-10 10:00:00')->toISOString(),
            $item['follow_up_at'],
        );
        $this->assertSame('scheduled_event_upcoming', $item['reason_code']);
        $this->assertSame(2, $item['days_from_reference']);
        $this->assertSame($event->id, $item['scheduled_event']['id']);
        $this->assertSame('interview', $item['scheduled_event']['event_type']);
        $this->assertSame('Technical interview', $item['scheduled_event']['title']);
    }

    public function test_queue_uses_the_earliest_date_between_next_action_and_event(): void
    {
        [$owner, $profile, $nextActionFirst] = $this->scenario([
            'next_action_at' => '2026-07-09 09:00:00',
        ]);
        $this->schedule($nextActionFirst, $owner, '2026-07-10 10:00:00');
        $eventFirst = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'API Developer',
            'company_name' => 'Beta',
            'status' => 'interview',
            'next_action_at' => '2026-07-12 09:00:00',
        ]);
        $event = $this->schedule($eventFirst, $owner, '2026-07-10 08:00:00');

        $queue = $this->queue($profile, $owner);
        $items = collect($queue['buckets']['upcoming'])->keyBy('application_id');

        $this->assertSame('next_action', $items[$nextActionFirst->id]['follow_up_source']);
        $this->assertSame('next_action_upcoming', $items[$nextActionFirst->id]['reason_code']);
        $this->assertSame('scheduled_event', $items[$eventFirst->id]['follow_up_source']);
        $this->assertSame($event->id, $items[$eventFirst->id]['scheduled_event']['id']);
    }

    public function test_equal_next_action_and_event_time_prefers_concrete_event(): void
    {
        [$owner, $profile, $application] = $this->scenario([
            'next_action_at' => '2026-07-10 10:00:00',
        ]);
        $this->schedule($application, $owner, '2026-07-10 10:00:00');

        $queue = $this->queue($profile, $owner);
        $item = $queue['buckets']['upcoming'][0];

        $this->assertSame('scheduled_event', $item['follow_up_source']);
        $this->assertSame('scheduled_event_upcoming', $item['reason_code']);
    }

    public function test_resolved_events_are_removed_from_follow_up_queue(): void
    {
        [$owner, $profile, $cancelledApplication] = $this->scenario();
        $cancelled = $this->schedule(
            $cancelledApplication,
            $owner,
            '2026-07-10 10:00:00',
        );
        CarbonImmutable::setTestNow('2026-07-09 12:00:00');
        app(ResolveJobApplicationScheduledEvent::class)->execute(
            $cancelled,
            $owner,
            [
                'status' => 'cancelled',
                'changed_at' => '2026-07-09 11:00:00',
            ],
        );

        $completedApplication = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'PHP Developer',
            'company_name' => 'Gamma',
            'status' => 'interview',
        ]);
        CarbonImmutable::setTestNow('2026-07-08 12:00:00');
        $completed = $this->schedule(
            $completedApplication,
            $owner,
            '2026-07-10 10:00:00',
        );
        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
        app(ResolveJobApplicationScheduledEvent::class)->execute(
            $completed,
            $owner,
            [
                'status' => 'completed',
                'changed_at' => '2026-07-10 11:00:00',
            ],
        );

        $queue = $this->queue(
            $profile,
            $owner,
            '2026-07-11 12:00:00',
        );

        $this->assertSame(
            [$cancelledApplication->id, $completedApplication->id],
            array_column($queue['buckets']['unscheduled'], 'application_id'),
        );
        $this->assertSame(0, $queue['summary']['overdue']['total']);
    }

    public function test_timeline_contains_planned_and_resolved_event_transitions(): void
    {
        [$owner, , $application] = $this->scenario();
        $event = $this->schedule($application, $owner, '2026-07-10 10:00:00');
        CarbonImmutable::setTestNow('2026-07-09 12:00:00');
        app(ResolveJobApplicationScheduledEvent::class)->execute(
            $event,
            $owner,
            [
                'status' => 'cancelled',
                'changed_at' => '2026-07-09 11:00:00',
                'notes' => 'Recruiter rescheduled externally.',
            ],
        );

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
            [
                'event_types' => [
                    JobApplicationTimelineBuilder::TYPE_SCHEDULED_EVENT_CHANGED,
                ],
                'direction' => 'asc',
            ],
        );

        $this->assertSame(2, $timeline['summary']['available_total']);
        $this->assertSame(2, $timeline['summary']['matching_total']);
        $this->assertSame(2, $timeline['summary']['returned_total']);
        $this->assertSame(2, $timeline['summary']['available_by_type']['scheduled_event_changed']);
        $this->assertSame(
            ['planned', 'cancelled'],
            array_column(array_column($timeline['events'], 'details'), 'status'),
        );
        $this->assertNull($timeline['events'][0]['details']['from_status']);
        $this->assertSame('planned', $timeline['events'][1]['details']['from_status']);
        $this->assertSame($event->id, $timeline['events'][0]['details']['scheduled_event_id']);
        $this->assertSame('Technical interview', $timeline['events'][0]['details']['title']);
        $this->assertSame(
            'Recruiter rescheduled externally.',
            $timeline['events'][1]['details']['change_notes'],
        );
    }

    private function scenario(array $overrides = []): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $application = JobApplication::create(array_merge([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'interview',
        ], $overrides));

        return [$owner, $profile, $application];
    }

    private function schedule(
        JobApplication $application,
        User $owner,
        string $startsAt,
    ) {
        return app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'event_type' => 'interview',
                'title' => 'Technical interview',
                'starts_at' => $startsAt,
                'ends_at' => CarbonImmutable::parse($startsAt)
                    ->addHour()
                    ->toDateTimeString(),
                'location' => 'Remote',
            ],
        );
    }

    private function queue(
        Profile $profile,
        User $owner,
        string $referenceAt = '2026-07-08 12:00:00',
    ): array {
        return app(BuildJobApplicationFollowUpQueue::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => $referenceAt,
                'upcoming_days' => 7,
                'limit_per_bucket' => 25,
            ],
        );
    }
}
