<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationPortfolioDashboard;
use App\Actions\Applications\ScheduleJobApplicationEvent;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildProfileApplicationPortfolioDashboardScheduledEventTest extends TestCase
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

    public function test_scheduled_event_due_today_is_exposed_as_dashboard_priority(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'draft',
        ]);
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'call-today',
                'event_type' => 'call',
                'title' => 'Recruiter call',
                'starts_at' => '2026-07-08 15:00:00',
                'ends_at' => '2026-07-08 15:30:00',
            ],
        );

        $dashboard = app(BuildProfileApplicationPortfolioDashboard::class)->execute(
            $profile,
            $owner,
            ['reference_at' => '2026-07-08 12:00:00'],
        );
        $item = $dashboard['priority_queue'][0];

        $this->assertSame(1, $dashboard['summary']['applications_total']);
        $this->assertSame(1, $dashboard['summary']['attention_total']);
        $this->assertSame(1, $dashboard['summary']['planned_events_total']);
        $this->assertSame(1, $dashboard['follow_up']['by_urgency']['today']);
        $this->assertSame(1, $dashboard['follow_up']['by_source']['scheduled_event']);
        $this->assertSame($application->id, $item['application_id']);
        $this->assertSame('follow_up_today', $item['primary_signal']);
        $this->assertSame(['follow_up_today'], $item['signals']);
        $this->assertSame('healthy', $item['integrity_status']);
        $this->assertSame('scheduled_event', $item['follow_up_source']);
        $this->assertSame('scheduled_event_due_today', $item['follow_up_reason_code']);
        $this->assertSame($event->id, $item['scheduled_event']['id']);
        $this->assertSame('Recruiter call', $item['scheduled_event']['title']);
    }
}
