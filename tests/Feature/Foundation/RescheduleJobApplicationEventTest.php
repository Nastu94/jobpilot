<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationFollowUpQueue;
use App\Actions\Applications\BuildJobApplicationTimeline;
use App\Actions\Applications\BuildJobApplicationWorkspace;
use App\Actions\Applications\RescheduleJobApplicationEvent;
use App\Actions\Applications\ResolveJobApplicationScheduledEvent;
use App\Actions\Applications\ScheduleJobApplicationEvent;
use App\Models\JobApplication;
use App\Models\JobApplicationScheduledEvent;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\JobApplicationTimelineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RescheduleJobApplicationEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-08 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_reschedule_a_planned_event_atomically(): void
    {
        [$owner, , $application, $previousEvent] = $this->scenario();
        $originalNextAction = $application->next_action_at?->toISOString();

        $replacement = app(RescheduleJobApplicationEvent::class)->execute(
            $previousEvent,
            $owner,
            $this->rescheduleInput(),
        );
        $previous = $replacement->previousEvent;
        $next = $replacement->replacementEvent;

        $this->assertSame($application->id, $replacement->job_application_id);
        $this->assertSame($previousEvent->id, $replacement->previous_scheduled_event_id);
        $this->assertSame($next->id, $replacement->replacement_scheduled_event_id);
        $this->assertSame($owner->id, $replacement->changed_by);
        $this->assertSame('reschedule-001', $replacement->client_reference);
        $this->assertSame('2026-07-08T11:00:00.000000Z', $replacement->changed_at->toISOString());
        $this->assertSame(
            "Recruiter moved the interview.\nKeep the same preparation.",
            $replacement->notes,
        );

        $this->assertSame('cancelled', $previous->status);
        $this->assertSame($owner->id, $previous->resolved_by);
        $this->assertSame('2026-07-08T11:00:00.000000Z', $previous->resolved_at->toISOString());
        $this->assertSame($replacement->notes, $previous->resolution_notes);
        $this->assertCount(2, $previous->statusHistory);
        $this->assertSame(
            ['planned', 'cancelled'],
            $previous->statusHistory->pluck('status')->all(),
        );

        $this->assertSame('planned', $next->status);
        $this->assertSame('event-new', $next->client_reference);
        $this->assertSame('interview', $next->event_type);
        $this->assertSame('Technical interview - new time', $next->title);
        $this->assertSame('2026-07-12T10:00:00.000000Z', $next->starts_at->toISOString());
        $this->assertSame('2026-07-12T11:00:00.000000Z', $next->ends_at->toISOString());
        $this->assertSame('Remote', $next->location);
        $this->assertSame('https://meet.example.com/new-room', $next->meeting_url);
        $this->assertSame('Mario Rossi', $next->contact_name);
        $this->assertSame('recruiter@example.com', $next->contact_email);
        $this->assertSame("Prepare Laravel examples.\nBring questions.", $next->notes);
        $this->assertCount(1, $next->statusHistory);
        $this->assertNull($next->statusHistory->first()->from_status);
        $this->assertSame('planned', $next->statusHistory->first()->status);
        $this->assertSame('2026-07-08T11:00:00.000000Z', $next->statusHistory->first()->changed_at->toISOString());

        $application = $application->fresh();
        $this->assertSame('interview', $application->status);
        $this->assertSame($originalNextAction, $application->next_action_at?->toISOString());
        $this->assertDatabaseCount('job_application_scheduled_event_replacements', 1);
        $this->assertDatabaseCount('job_application_scheduled_events', 2);
        $this->assertDatabaseCount('job_application_scheduled_event_histories', 3);
        $this->assertDatabaseCount('job_application_status_histories', 0);
        $this->assertDatabaseCount('job_application_tracking_histories', 0);
    }

    public function test_same_reference_and_payload_are_idempotent_even_after_event_time(): void
    {
        [$owner, , , $previousEvent] = $this->scenario();
        $input = $this->rescheduleInput();
        unset($input['changed_at']);
        $action = app(RescheduleJobApplicationEvent::class);

        $first = $action->execute($previousEvent, $owner, $input);
        CarbonImmutable::setTestNow('2026-07-13 12:00:00');
        $second = $action->execute($previousEvent, $owner, $input);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            $first->replacement_scheduled_event_id,
            $second->replacement_scheduled_event_id,
        );
        $this->assertDatabaseCount('job_application_scheduled_event_replacements', 1);
        $this->assertDatabaseCount('job_application_scheduled_events', 2);
        $this->assertDatabaseCount('job_application_scheduled_event_histories', 3);
    }

    public function test_same_reference_with_changed_payload_is_rejected(): void
    {
        [$owner, , , $previousEvent] = $this->scenario();
        $action = app(RescheduleJobApplicationEvent::class);
        $action->execute($previousEvent, $owner, $this->rescheduleInput());
        $changed = $this->rescheduleInput();
        $changed['replacement_event']['title'] = 'Different replacement title';

        try {
            $action->execute($previousEvent, $owner, $changed);

            $this->fail('A reschedule reference was reused with another payload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_reference', $exception->errors());
            $this->assertDatabaseCount('job_application_scheduled_event_replacements', 1);
            $this->assertDatabaseCount('job_application_scheduled_events', 2);
        }
    }

    public function test_event_cannot_be_replaced_twice_with_different_references(): void
    {
        [$owner, , , $previousEvent] = $this->scenario();
        $action = app(RescheduleJobApplicationEvent::class);
        $action->execute($previousEvent, $owner, $this->rescheduleInput());
        $second = $this->rescheduleInput();
        $second['client_reference'] = 'reschedule-002';
        $second['replacement_event']['client_reference'] = 'event-new-2';

        try {
            $action->execute($previousEvent, $owner, $second);

            $this->fail('A scheduled event was replaced twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_event', $exception->errors());
            $this->assertDatabaseCount('job_application_scheduled_event_replacements', 1);
        }
    }

    public function test_only_planned_events_can_be_rescheduled(): void
    {
        [$owner, , , $previousEvent] = $this->scenario();
        app(ResolveJobApplicationScheduledEvent::class)->execute(
            $previousEvent,
            $owner,
            [
                'status' => 'cancelled',
                'changed_at' => '2026-07-08 11:00:00',
            ],
        );

        try {
            app(RescheduleJobApplicationEvent::class)->execute(
                $previousEvent,
                $owner,
                $this->rescheduleInput(),
            );

            $this->fail('A resolved event was rescheduled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_event', $exception->errors());
            $this->assertDatabaseCount('job_application_scheduled_event_replacements', 0);
            $this->assertDatabaseCount('job_application_scheduled_events', 1);
        }
    }

    public function test_terminal_application_cannot_receive_a_replacement_event(): void
    {
        [$owner, , $application, $previousEvent] = $this->scenario();
        $application->forceFill(['status' => 'rejected'])->save();

        try {
            app(RescheduleJobApplicationEvent::class)->execute(
                $previousEvent,
                $owner,
                $this->rescheduleInput(),
            );

            $this->fail('A terminal application received a replacement event.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('job_application', $exception->errors());
            $this->assertSame('planned', $previousEvent->fresh()->status);
            $this->assertDatabaseCount('job_application_scheduled_event_replacements', 0);
        }
    }

    public function test_future_or_backdated_reschedule_time_is_rejected(): void
    {
        [$owner, , , $previousEvent] = $this->scenario();
        $action = app(RescheduleJobApplicationEvent::class);
        $future = $this->rescheduleInput();
        $future['changed_at'] = '2026-07-08 13:00:00';

        try {
            $action->execute($previousEvent, $owner, $future);

            $this->fail('A future reschedule timestamp was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
        }

        $backdated = $this->rescheduleInput();
        $backdated['changed_at'] = '2026-07-08 09:00:00';

        try {
            $action->execute($previousEvent, $owner, $backdated);

            $this->fail('A backdated reschedule timestamp was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame('planned', $previousEvent->fresh()->status);
            $this->assertDatabaseCount('job_application_scheduled_event_replacements', 0);
        }
    }

    public function test_reschedule_and_replacement_event_inputs_are_strictly_validated(): void
    {
        [$owner, , , $previousEvent] = $this->scenario();
        $action = app(RescheduleJobApplicationEvent::class);
        $invalidInputs = [];

        $unknown = $this->rescheduleInput();
        $unknown['unknown'] = true;
        $invalidInputs[] = $unknown;

        $missingReference = $this->rescheduleInput();
        unset($missingReference['client_reference']);
        $invalidInputs[] = $missingReference;

        $invalidEnd = $this->rescheduleInput();
        $invalidEnd['replacement_event']['ends_at'] = '2026-07-12 09:00:00';
        $invalidInputs[] = $invalidEnd;

        $pastStart = $this->rescheduleInput();
        $pastStart['replacement_event']['starts_at'] = '2026-07-08 11:30:00';
        $pastStart['replacement_event']['ends_at'] = '2026-07-08 12:30:00';
        $invalidInputs[] = $pastStart;

        $invalidUrl = $this->rescheduleInput();
        $invalidUrl['replacement_event']['meeting_url'] = 'ftp://example.com/room';
        $invalidInputs[] = $invalidUrl;

        foreach ($invalidInputs as $input) {
            try {
                $action->execute($previousEvent, $owner, $input);

                $this->fail('Invalid reschedule input was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
                $this->assertSame('planned', $previousEvent->fresh()->status);
            }
        }

        $this->assertDatabaseCount('job_application_scheduled_event_replacements', 0);
        $this->assertDatabaseCount('job_application_scheduled_events', 1);
    }

    public function test_replacement_event_client_reference_must_be_available(): void
    {
        [$owner, , $application, $previousEvent] = $this->scenario();
        app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'event-new',
                'event_type' => 'recruiter_call',
                'title' => 'Separate recruiter call',
                'starts_at' => '2026-07-11 09:00:00',
            ],
        );

        try {
            app(RescheduleJobApplicationEvent::class)->execute(
                $previousEvent,
                $owner,
                $this->rescheduleInput(),
            );

            $this->fail('An existing event client reference was reused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'replacement_event.client_reference',
                $exception->errors(),
            );
            $this->assertSame('planned', $previousEvent->fresh()->status);
            $this->assertDatabaseCount('job_application_scheduled_event_replacements', 0);
            $this->assertDatabaseCount('job_application_scheduled_events', 2);
        }
    }

    public function test_outsider_cannot_reschedule_another_users_event(): void
    {
        [, , , $previousEvent] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(RescheduleJobApplicationEvent::class)->execute(
            $previousEvent,
            $outsider,
            $this->rescheduleInput(),
        );
    }

    public function test_application_delete_cascades_replacement_and_event_history(): void
    {
        [$owner, , $application, $previousEvent] = $this->scenario();
        app(RescheduleJobApplicationEvent::class)->execute(
            $previousEvent,
            $owner,
            $this->rescheduleInput(),
        );

        $application->delete();

        $this->assertDatabaseCount('job_application_scheduled_event_replacements', 0);
        $this->assertDatabaseCount('job_application_scheduled_events', 0);
        $this->assertDatabaseCount('job_application_scheduled_event_histories', 0);
    }

    public function test_reschedule_updates_queue_timeline_and_workspace_read_models(): void
    {
        [$owner, $profile, $application, $previousEvent] = $this->scenario();
        $replacement = app(RescheduleJobApplicationEvent::class)->execute(
            $previousEvent,
            $owner,
            $this->rescheduleInput(),
        );
        $next = $replacement->replacementEvent;

        $queue = app(BuildJobApplicationFollowUpQueue::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-07-08 12:00:00',
                'upcoming_days' => 7,
                'limit_per_bucket' => 25,
            ],
        );
        $queueItem = $queue['buckets']['upcoming'][0];

        $this->assertSame($next->id, $queueItem['scheduled_event']['id']);
        $this->assertSame('scheduled_event', $queueItem['follow_up_source']);
        $this->assertSame('2026-07-12T10:00:00.000000Z', $queueItem['follow_up_at']);

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

        $this->assertSame(3, $timeline['summary']['available_total']);
        $this->assertSame(
            ['planned', 'cancelled', 'planned'],
            array_column(array_column($timeline['events'], 'details'), 'status'),
        );
        $this->assertSame(
            $next->id,
            $timeline['events'][0]['details']['replaced_by_scheduled_event_id'],
        );
        $this->assertSame(
            $next->id,
            $timeline['events'][1]['details']['replaced_by_scheduled_event_id'],
        );
        $this->assertSame(
            $previousEvent->id,
            $timeline['events'][2]['details']['replaces_scheduled_event_id'],
        );

        $workspace = app(BuildJobApplicationWorkspace::class)->execute(
            $application,
            $owner,
            [
                'reference_at' => '2026-07-08 12:00:00',
                'upcoming_days' => 7,
            ],
        );

        $this->assertSame($next->id, $workspace['next_planned_event']['id']);
        $this->assertSame(
            $previousEvent->id,
            $workspace['next_planned_event']['replaces_scheduled_event_id'],
        );
        $this->assertTrue($workspace['signals']['has_event_replacements']);
        $this->assertSame(2, $workspace['counts']['scheduled_events_total']);
        $this->assertSame(1, $workspace['counts']['planned_events_total']);
        $this->assertSame(1, $workspace['counts']['event_replacements_total']);
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
            'next_action_at' => '2026-07-15 09:00:00',
        ]);
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'event-old',
                'event_type' => 'interview',
                'title' => 'Technical interview',
                'starts_at' => '2026-07-10 10:00:00',
                'ends_at' => '2026-07-10 11:00:00',
                'location' => 'Remote',
            ],
        );
        CarbonImmutable::setTestNow('2026-07-08 12:00:00');

        return [$owner, $profile, $application, $event];
    }

    private function rescheduleInput(): array
    {
        return [
            'client_reference' => '  reschedule-001  ',
            'changed_at' => '2026-07-08 11:00:00',
            'notes' => "  Recruiter moved the interview.\nKeep the same preparation.  ",
            'replacement_event' => [
                'client_reference' => '  event-new  ',
                'event_type' => ' interview ',
                'title' => ' Technical interview - new time ',
                'starts_at' => '2026-07-12 10:00:00',
                'ends_at' => '2026-07-12 11:00:00',
                'location' => ' Remote ',
                'meeting_url' => ' https://meet.example.com/new-room ',
                'contact_name' => ' Mario Rossi ',
                'contact_email' => ' RECRUITER@EXAMPLE.COM ',
                'notes' => "  Prepare Laravel examples.\nBring questions.  ",
            ],
        ];
    }
}
