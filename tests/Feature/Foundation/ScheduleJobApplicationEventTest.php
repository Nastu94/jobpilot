<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\ScheduleJobApplicationEvent;
use App\Models\JobApplication;
use App\Models\JobApplicationScheduledEvent;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleJobApplicationEventTest extends TestCase
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

    public function test_owner_can_schedule_a_normalized_event_with_initial_history(): void
    {
        [$owner, $application] = $this->scenario();

        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => '  ui:event:001  ',
                'event_type' => 'interview',
                'title' => '  Technical   interview  ',
                'starts_at' => '2026-07-10 10:00:00',
                'ends_at' => '2026-07-10 11:00:00',
                'location' => '  Rome   office  ',
                'meeting_url' => '  https://meet.example.com/room  ',
                'contact_name' => '  Mario   Rossi  ',
                'contact_email' => ' Recruiter@Example.COM ',
                'notes' => "  Prepare Laravel examples.\nBring questions.  ",
            ],
        );

        $this->assertSame($application->id, $event->job_application_id);
        $this->assertSame($owner->id, $event->created_by);
        $this->assertTrue($event->createdBy->is($owner));
        $this->assertSame('ui:event:001', $event->client_reference);
        $this->assertSame('interview', $event->event_type);
        $this->assertSame('Technical interview', $event->title);
        $this->assertSame('2026-07-10 10:00:00', $event->starts_at->toDateTimeString());
        $this->assertSame('2026-07-10 11:00:00', $event->ends_at->toDateTimeString());
        $this->assertSame('Rome office', $event->location);
        $this->assertSame('https://meet.example.com/room', $event->meeting_url);
        $this->assertSame('Mario Rossi', $event->contact_name);
        $this->assertSame('recruiter@example.com', $event->contact_email);
        $this->assertSame("Prepare Laravel examples.\nBring questions.", $event->notes);
        $this->assertSame('planned', $event->status);
        $this->assertNull($event->resolved_at);
        $this->assertCount(1, $event->statusHistory);
        $this->assertNull($event->statusHistory->first()->from_status);
        $this->assertSame('planned', $event->statusHistory->first()->status);
        $this->assertSame($owner->id, $event->statusHistory->first()->changed_by);
    }

    public function test_same_client_reference_and_payload_are_idempotent(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(ScheduleJobApplicationEvent::class);
        $payload = $this->payload(['client_reference' => 'event-retry-001']);

        $first = $action->execute($application, $owner, $payload);
        $second = $action->execute($application, $owner, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('job_application_scheduled_events', 1);
        $this->assertDatabaseCount('job_application_scheduled_event_histories', 1);
    }

    public function test_same_client_reference_with_different_payload_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(ScheduleJobApplicationEvent::class);
        $action->execute($application, $owner, $this->payload([
            'client_reference' => 'event-retry-002',
        ]));

        try {
            $action->execute($application, $owner, $this->payload([
                'client_reference' => 'event-retry-002',
                'title' => 'Different event',
            ]));

            $this->fail('A reused client reference accepted another event payload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_reference', $exception->errors());
            $this->assertDatabaseCount('job_application_scheduled_events', 1);
            $this->assertDatabaseCount('job_application_scheduled_event_histories', 1);
        }
    }

    public function test_event_must_start_in_future_and_end_after_start(): void
    {
        [$owner, $application] = $this->scenario();

        foreach ([
            $this->payload(['starts_at' => '2026-07-08 11:59:59']),
            $this->payload([
                'starts_at' => '2026-07-10 10:00:00',
                'ends_at' => '2026-07-10 10:00:00',
            ]),
            $this->payload([
                'starts_at' => '2026-07-10 10:00:00',
                'ends_at' => '2026-07-10 09:59:59',
            ]),
        ] as $payload) {
            try {
                app(ScheduleJobApplicationEvent::class)->execute(
                    $application,
                    $owner,
                    $payload,
                );

                $this->fail('An invalid scheduled event time was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('job_application_scheduled_events', 0);
    }

    public function test_event_options_are_strictly_validated(): void
    {
        [$owner, $application] = $this->scenario();

        foreach ([
            $this->payload(['event_type' => 'video_game']),
            $this->payload(['title' => '   ']),
            $this->payload(['meeting_url' => 'ftp://example.com/room']),
            $this->payload(['contact_email' => 'not-an-email']),
            $this->payload(['unknown' => true]),
        ] as $payload) {
            try {
                app(ScheduleJobApplicationEvent::class)->execute(
                    $application,
                    $owner,
                    $payload,
                );

                $this->fail('Invalid scheduled event data was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('job_application_scheduled_events', 0);
    }

    public function test_terminal_application_cannot_receive_a_new_event(): void
    {
        [$owner, $application] = $this->scenario();
        $application->forceFill(['status' => 'rejected'])->save();

        try {
            app(ScheduleJobApplicationEvent::class)->execute(
                $application,
                $owner,
                $this->payload(),
            );

            $this->fail('A terminal application received a scheduled event.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('job_application', $exception->errors());
            $this->assertDatabaseCount('job_application_scheduled_events', 0);
        }
    }

    public function test_user_cannot_schedule_an_event_for_another_users_application(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $outsider,
            $this->payload(),
        );
    }

    public function test_event_and_history_are_deleted_with_application(): void
    {
        [$owner, $application] = $this->scenario();
        app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            $this->payload(),
        );

        $application->delete();

        $this->assertDatabaseCount('job_application_scheduled_events', 0);
        $this->assertDatabaseCount('job_application_scheduled_event_histories', 0);
    }

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'interview',
        ]);

        return [$owner, $application];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_type' => 'interview',
            'title' => 'Technical interview',
            'starts_at' => '2026-07-10 10:00:00',
            'ends_at' => '2026-07-10 11:00:00',
            'location' => 'Remote',
            'meeting_url' => 'https://meet.example.com/room',
            'contact_name' => 'Mario Rossi',
            'contact_email' => 'mario@example.com',
            'notes' => 'Prepare Laravel examples.',
        ], $overrides);
    }
}
