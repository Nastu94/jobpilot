<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationWorkspace;
use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobApplicationInteraction;
use App\Models\JobApplicationScheduledEvent;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildJobApplicationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_build_deterministic_workspace_without_side_effects(): void
    {
        CarbonImmutable::setTestNow('2026-07-08 10:00:00');
        [$owner, $application, $version] = $this->scenario([
            'application' => [
                'next_action_at' => '2026-07-10 09:00:00',
            ],
        ]);
        $olderInteraction = $this->interaction(
            $application,
            $owner,
            '2026-07-07 12:00:00',
            'Older message',
        );
        $latestInteraction = $this->interaction(
            $application,
            $owner,
            '2026-07-08 08:30:00',
            'Recruiter reply',
        );
        $event = $this->scheduledEvent(
            $application,
            $owner,
            '2026-07-09 09:00:00',
        );
        $action = app(BuildJobApplicationWorkspace::class);
        $input = [
            'reference_at' => '2026-07-08 10:00:00',
            'upcoming_days' => 7,
            'timeline_limit' => 2,
        ];

        $first = $action->execute($application, $owner, $input);
        $second = $action->execute($application, $owner, $input);

        $this->assertSame($first, $second);
        $this->assertSame($application->id, $first['application']['id']);
        $this->assertSame('Backend Developer', $first['application']['job_title']);
        $this->assertSame('Acme', $first['application']['company_name']);
        $this->assertSame('draft', $first['application']['status']);
        $this->assertSame('company_site', $first['posting']['source']);
        $this->assertSame('https://jobs.example.com/backend', $first['posting']['source_url']);
        $this->assertSame('selected_version', $first['document']['document_source']);
        $this->assertSame($version->id, $first['document']['generated_document_version_id']);
        $this->assertSame('targeted-cv-v1.md', $first['document']['filename']);
        $this->assertArrayNotHasKey('contents', $first['document']);
        $this->assertArrayNotHasKey('storage_disk', $first['document']);
        $this->assertArrayNotHasKey('storage_path', $first['document']);
        $this->assertTrue($first['submission_readiness']['ready']);
        $this->assertSame('scheduled_event', $first['follow_up']['follow_up_source']);
        $this->assertSame('2026-07-09T09:00:00.000000Z', $first['follow_up']['follow_up_at']);
        $this->assertSame('upcoming', $first['follow_up']['urgency']);
        $this->assertSame('scheduled_event_upcoming', $first['follow_up']['reason_code']);
        $this->assertSame($latestInteraction->id, $first['latest_interaction']['id']);
        $this->assertSame('Recruiter reply', $first['latest_interaction']['subject']);
        $this->assertSame($event->id, $first['next_planned_event']['id']);
        $this->assertSame('Technical interview', $first['next_planned_event']['title']);
        $this->assertFalse($first['signals']['is_terminal']);
        $this->assertTrue($first['signals']['is_active']);
        $this->assertTrue($first['signals']['submission_ready']);
        $this->assertTrue($first['signals']['has_follow_up']);
        $this->assertTrue($first['signals']['has_planned_event']);
        $this->assertSame(2, $first['counts']['interactions_total']);
        $this->assertSame(1, $first['counts']['scheduled_events_total']);
        $this->assertSame(1, $first['counts']['planned_events_total']);
        $this->assertSame(3, $first['counts']['timeline_events_total']);
        $this->assertSame(2, $first['counts']['timeline_events_returned']);
        $this->assertSame(3, $first['timeline']['summary']['available_total']);
        $this->assertSame(2, $first['timeline']['summary']['returned_total']);
        $this->assertSame('scheduled_event_changed', $first['timeline']['events'][0]['event_type']);
        $this->assertSame('interaction_recorded', $first['timeline']['events'][1]['event_type']);
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
        $this->assertDatabaseCount('job_application_status_histories', 0);
        $this->assertDatabaseCount('job_application_tracking_histories', 0);
        $this->assertDatabaseHas('job_application_interactions', [
            'id' => $olderInteraction->id,
        ]);
        $this->assertSame('draft', $application->fresh()->status);
    }

    public function test_earlier_next_action_is_selected_while_next_event_remains_visible(): void
    {
        CarbonImmutable::setTestNow('2026-07-08 10:00:00');
        [$owner, $application] = $this->scenario([
            'application' => [
                'next_action_at' => '2026-07-09 08:00:00',
            ],
        ]);
        $event = $this->scheduledEvent(
            $application,
            $owner,
            '2026-07-10 09:00:00',
        );

        $workspace = app(BuildJobApplicationWorkspace::class)->execute(
            $application,
            $owner,
            [
                'reference_at' => '2026-07-08 10:00:00',
                'upcoming_days' => 7,
            ],
        );

        $this->assertSame('next_action', $workspace['follow_up']['follow_up_source']);
        $this->assertSame('2026-07-09T08:00:00.000000Z', $workspace['follow_up']['follow_up_at']);
        $this->assertSame('next_action_upcoming', $workspace['follow_up']['reason_code']);
        $this->assertSame($event->id, $workspace['follow_up']['scheduled_event']['id']);
        $this->assertSame($event->id, $workspace['next_planned_event']['id']);
    }

    public function test_missing_posting_document_and_follow_up_are_reported_safely(): void
    {
        CarbonImmutable::setTestNow('2026-07-08 10:00:00');
        [$owner, $application] = $this->scenario();
        $application->forceFill([
            'job_posting_id' => null,
            'generated_document_version_id' => null,
            'next_action_at' => null,
        ])->save();

        $workspace = app(BuildJobApplicationWorkspace::class)->execute(
            $application,
            $owner,
            ['reference_at' => '2026-07-08 10:00:00'],
        );

        $this->assertNull($workspace['posting']);
        $this->assertNull($workspace['document']);
        $this->assertFalse($workspace['submission_readiness']['ready']);
        $this->assertContains(
            'selected_version_missing',
            array_column($workspace['submission_readiness']['blockers'], 'code'),
        );
        $this->assertNull($workspace['follow_up']['follow_up_at']);
        $this->assertNull($workspace['follow_up']['follow_up_source']);
        $this->assertSame('unscheduled', $workspace['follow_up']['urgency']);
        $this->assertSame(
            'active_application_without_next_action',
            $workspace['follow_up']['reason_code'],
        );
        $this->assertNull($workspace['latest_interaction']);
        $this->assertNull($workspace['next_planned_event']);
        $this->assertFalse($workspace['signals']['submission_ready']);
        $this->assertFalse($workspace['signals']['has_follow_up']);
        $this->assertFalse($workspace['signals']['has_planned_event']);
    }

    public function test_applied_application_uses_submitted_document_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-07-08 10:00:00');
        [$owner, $application, $version] = $this->scenario();
        $applied = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => '2026-07-08 09:00:00',
            ],
        );

        $workspace = app(BuildJobApplicationWorkspace::class)->execute(
            $applied,
            $owner,
            ['reference_at' => '2026-07-08 10:00:00'],
        );

        $this->assertSame('applied', $workspace['application']['status']);
        $this->assertSame('submitted_snapshot', $workspace['document']['document_source']);
        $this->assertSame($version->id, $workspace['document']['generated_document_version_id']);
        $this->assertSame('targeted-cv-v1.md', $workspace['document']['filename']);
        $this->assertArrayNotHasKey('storage_path', $workspace['document']);
        $this->assertFalse($workspace['submission_readiness']['ready']);
        $this->assertContains(
            'application_not_draft',
            array_column($workspace['submission_readiness']['blockers'], 'code'),
        );
        $this->assertFalse($workspace['signals']['is_terminal']);
        $this->assertTrue($workspace['signals']['is_active']);
        $this->assertSame(1, $workspace['timeline']['summary']['available_total']);
        $this->assertSame('status_changed', $workspace['timeline']['events'][0]['event_type']);
    }

    public function test_terminal_application_is_marked_inactive_and_keeps_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-07-08 10:00:00');
        [$owner, $application, $version] = $this->scenario();
        $transition = app(TransitionJobApplicationStatus::class);
        $applied = $transition->execute($application, $owner, [
            'status' => 'applied',
            'changed_at' => '2026-07-08 08:00:00',
        ]);
        $rejected = $transition->execute($applied, $owner, [
            'status' => 'rejected',
            'changed_at' => '2026-07-08 09:00:00',
        ]);

        $workspace = app(BuildJobApplicationWorkspace::class)->execute(
            $rejected,
            $owner,
            ['reference_at' => '2026-07-08 10:00:00'],
        );

        $this->assertSame('rejected', $workspace['application']['status']);
        $this->assertTrue($workspace['signals']['is_terminal']);
        $this->assertFalse($workspace['signals']['is_active']);
        $this->assertSame('submitted_snapshot', $workspace['document']['document_source']);
        $this->assertSame($version->id, $workspace['document']['generated_document_version_id']);
        $this->assertSame(2, $workspace['timeline']['summary']['available_total']);
    }

    public function test_workspace_options_are_strictly_validated(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(BuildJobApplicationWorkspace::class);

        foreach ([
            ['unknown' => true],
            ['upcoming_days' => 0],
            ['upcoming_days' => 31],
            ['timeline_limit' => 0],
            ['timeline_limit' => 101],
            ['reference_at' => 'not-a-date'],
        ] as $input) {
            try {
                $action->execute($application, $owner, $input);

                $this->fail('Invalid workspace options were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_outsider_cannot_build_application_workspace(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(BuildJobApplicationWorkspace::class)->execute(
            $application,
            $outsider,
        );
    }

    private function scenario(array $overrides = []): array
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Acme',
            'source' => 'company_site',
            'external_id' => 'JOB-001',
            'source_url' => 'https://jobs.example.com/backend',
            'location' => 'Rome',
            'country_code' => 'IT',
            'remote_type' => 'hybrid',
            'employment_type' => 'full_time',
            'seniority' => 'junior',
            'status' => 'active',
        ]);
        $resume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'Main CV',
        ]);
        $sourceVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'ready',
        ]);
        $content = '# Final targeted resume';
        $checksum = hash('sha256', $content);
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'source_resume_version_id' => $sourceVersion->id,
            'version_number' => 1,
            'generation_method' => 'manual',
            'generator_key' => 'manual_targeted_resume_finalization',
            'generator_version' => '1.0.0',
            'content_format' => 'markdown',
            'content' => $content,
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
            'reviewed_by' => $owner->id,
            'reviewed_at' => '2026-07-08 07:00:00',
            'reviewed_content_sha256' => $checksum,
        ]);
        $path = sprintf(
            'generated-documents/profile-%d/document-%d/version-%d/targeted-cv-v1.md',
            $profile->id,
            $document->id,
            $version->id,
        );
        Storage::disk('local')->put($path, $content);
        $version->forceFill([
            'storage_disk' => 'local',
            'storage_path' => $path,
            'filename' => 'targeted-cv-v1.md',
            'mime_type' => 'text/markdown',
            'file_size' => strlen($content),
            'checksum_sha256' => $checksum,
        ])->save();
        $application = JobApplication::create(array_merge([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $sourceVersion->id,
            'generated_document_version_id' => $version->id,
            'job_title' => $posting->title,
            'company_name' => $posting->company_name,
            'status' => 'draft',
            'application_channel' => 'company_website',
            'external_reference' => 'APP-001',
            'notes' => 'Manual application notes',
        ], $overrides['application'] ?? []));
        $document->forceFill(['job_application_id' => $application->id])->save();

        return [
            $owner,
            $application,
            $version->fresh(),
            $content,
            $path,
        ];
    }

    private function interaction(
        JobApplication $application,
        User $owner,
        string $occurredAt,
        string $subject,
    ): JobApplicationInteraction {
        return JobApplicationInteraction::create([
            'job_application_id' => $application->id,
            'recorded_by' => $owner->id,
            'interaction_type' => 'recruiter_message',
            'direction' => 'inbound',
            'subject' => $subject,
            'contact_name' => 'Mario Rossi',
            'contact_email' => 'recruiter@example.com',
            'occurred_at' => $occurredAt,
            'notes' => 'Interaction notes',
        ]);
    }

    private function scheduledEvent(
        JobApplication $application,
        User $owner,
        string $startsAt,
    ): JobApplicationScheduledEvent {
        $event = JobApplicationScheduledEvent::create([
            'job_application_id' => $application->id,
            'created_by' => $owner->id,
            'client_reference' => 'event-001',
            'event_type' => 'interview',
            'title' => 'Technical interview',
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addHour(),
            'location' => 'Rome office',
            'meeting_url' => 'https://meet.example.com/room',
            'contact_name' => 'Mario Rossi',
            'contact_email' => 'recruiter@example.com',
            'notes' => 'Prepare Laravel examples.',
            'status' => 'planned',
        ]);
        $event->statusHistory()->create([
            'job_application_id' => $application->id,
            'changed_by' => $owner->id,
            'from_status' => null,
            'status' => 'planned',
            'changed_at' => '2026-07-08 09:00:00',
            'notes' => 'Scheduled manually.',
        ]);

        return $event;
    }
}
