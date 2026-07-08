<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\ResolveJobApplicationScheduledEvent;
use App\Actions\Applications\ScheduleJobApplicationEvent;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResolveJobApplicationScheduledEventTest extends TestCase
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

    public function test_owner_can_complete_an_event_after_it_starts(): void
    {
        [$owner, , $event] = $this->scenario();
        CarbonImmutable::setTestNow('2026-07-10 12:00:00');

        $resolved = app(ResolveJobApplicationScheduledEvent::class)->execute(
            $event,
            $owner,
            [
                'status' => 'completed',
                'changed_at' => '2026-07-10 11:30:00',
                'notes' => "  Interview completed.\nWaiting for feedback.  ",
            ],
        );

        $this->assertSame('completed', $resolved->status);
        $this->assertSame($owner->id, $resolved->resolved_by);
        $this->assertTrue($resolved->resolvedBy->is($owner));
        $this->assertSame('2026-07-10 11:30:00', $resolved->resolved_at->toDateTimeString());
        $this->assertSame("Interview completed.\nWaiting for feedback.", $resolved->resolution_notes);
        $this->assertCount(2, $resolved->statusHistory);

        $history = $resolved->statusHistory->last();
        $this->assertSame('planned', $history->from_status);
        $this->assertSame('completed', $history->status);
        $this->assertSame($owner->id, $history->changed_by);
        $this->assertSame('2026-07-10 11:30:00', $history->changed_at->toDateTimeString());
        $this->assertSame("Interview completed.\nWaiting for feedback.", $history->notes);
    }

    public function test_owner_can_cancel_an_event_before_it_starts(): void
    {
        [$owner, , $event] = $this->scenario();
        CarbonImmutable::setTestNow('2026-07-09 12:00:00');

        $resolved = app(ResolveJobApplicationScheduledEvent::class)->execute(
            $event,
            $owner,
            [
                'status' => 'cancelled',
                'changed_at' => '2026-07-09 11:00:00',
                'notes' => 'Recruiter requested cancellation.',
            ],
        );

        $this->assertSame('cancelled', $resolved->status);
        $this->assertSame('Recruiter requested cancellation.', $resolved->resolution_notes);
        $this->assertSame('cancelled', $resolved->statusHistory->last()->status);
    }

    public function test_repeating_the_current_resolution_is_idempotent(): void
    {
        [$owner, , $event] = $this->scenario();
        CarbonImmutable::setTestNow('2026-07-09 12:00:00');
        $action = app(ResolveJobApplicationScheduledEvent::class);
        $first = $action->execute($event, $owner, [
            'status' => 'cancelled',
            'changed_at' => '2026-07-09 11:00:00',
        ]);
        $second = $action->execute($first, $owner, [
            'status' => 'cancelled',
            'changed_at' => '2026-07-09 11:30:00',
            'notes' => 'Replay must not overwrite the first resolution.',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertNull($second->resolution_notes);
        $this->assertDatabaseCount('job_application_scheduled_event_histories', 2);
    }

    public function test_resolved_event_cannot_transition_to_another_terminal_status(): void
    {
        [$owner, , $event] = $this->scenario();
        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
        $completed = app(ResolveJobApplicationScheduledEvent::class)->execute(
            $event,
            $owner,
            [
                'status' => 'completed',
                'changed_at' => '2026-07-10 11:00:00',
            ],
        );

        try {
            app(ResolveJobApplicationScheduledEvent::class)->execute(
                $completed,
                $owner,
                [
                    'status' => 'cancelled',
                    'changed_at' => '2026-07-10 11:30:00',
                ],
            );

            $this->fail('A completed event transitioned to cancelled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame('completed', $completed->fresh()->status);
            $this->assertDatabaseCount('job_application_scheduled_event_histories', 2);
        }
    }

    public function test_event_cannot_be_completed_before_it_starts(): void
    {
        [$owner, , $event] = $this->scenario();
        CarbonImmutable::setTestNow('2026-07-09 12:00:00');

        try {
            app(ResolveJobApplicationScheduledEvent::class)->execute(
                $event,
                $owner,
                [
                    'status' => 'completed',
                    'changed_at' => '2026-07-09 11:00:00',
                ],
            );

            $this->fail('An event was completed before its start.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame('planned', $event->fresh()->status);
            $this->assertDatabaseCount('job_application_scheduled_event_histories', 1);
        }
    }

    public function test_future_or_backdated_resolution_is_rejected(): void
    {
        [$owner, , $event] = $this->scenario();
        CarbonImmutable::setTestNow('2026-07-09 12:00:00');

        foreach ([
            '2026-07-09 13:00:00',
            '2026-07-08 11:00:00',
        ] as $changedAt) {
            try {
                app(ResolveJobApplicationScheduledEvent::class)->execute(
                    $event,
                    $owner,
                    [
                        'status' => 'cancelled',
                        'changed_at' => $changedAt,
                    ],
                );

                $this->fail('An invalid resolution chronology was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('planned', $event->fresh()->status);
        $this->assertDatabaseCount('job_application_scheduled_event_histories', 1);
    }

    public function test_resolution_options_are_strictly_validated(): void
    {
        [$owner, , $event] = $this->scenario();

        foreach ([
            ['status' => 'planned'],
            ['status' => 'unknown'],
            ['status' => 'cancelled', 'unknown' => true],
        ] as $input) {
            try {
                app(ResolveJobApplicationScheduledEvent::class)->execute(
                    $event,
                    $owner,
                    $input,
                );

                $this->fail('Invalid scheduled event resolution data was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_user_cannot_resolve_another_users_scheduled_event(): void
    {
        [, , $event] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(ResolveJobApplicationScheduledEvent::class)->execute(
            $event,
            $outsider,
            ['status' => 'cancelled'],
        );
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
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'event_type' => 'interview',
                'title' => 'Technical interview',
                'starts_at' => '2026-07-10 10:00:00',
                'ends_at' => '2026-07-10 11:00:00',
            ],
        );

        return [$owner, $application, $event];
    }
}
