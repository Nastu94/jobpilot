<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\ConfirmJobApplicationManualSubmission;
use App\Actions\Applications\InspectJobApplicationIntegrity;
use App\Actions\Applications\RescheduleJobApplicationEvent;
use App\Actions\Applications\ScheduleJobApplicationEvent;
use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InspectJobApplicationIntegrityTest extends TestCase
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

    public function test_clean_draft_is_healthy_even_before_submission(): void
    {
        [$owner, $application] = $this->scenario();

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );

        $this->assertSame($application->id, $audit['application_id']);
        $this->assertSame('draft', $audit['application_status']);
        $this->assertSame('healthy', $audit['integrity_status']);
        $this->assertTrue($audit['healthy']);
        $this->assertSame([
            'errors' => 0,
            'warnings' => 0,
            'total' => 0,
        ], $audit['summary']);
        $this->assertSame([], $audit['issues']);
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    public function test_confirmed_applied_application_is_healthy(): void
    {
        [$owner, $application] = $this->scenario();
        $this->confirm($application, $owner);

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );

        $this->assertSame('applied', $audit['application_status']);
        $this->assertSame('healthy', $audit['integrity_status']);
        $this->assertTrue($audit['healthy']);
        $this->assertSame(0, $audit['summary']['total']);
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    public function test_application_state_and_latest_history_mismatches_are_errors(): void
    {
        [$owner, $application] = $this->scenario();
        $this->confirm($application, $owner);
        $application->forceFill([
            'status' => 'rejected',
            'next_action_at' => '2026-07-15 09:00:00',
        ])->save();

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );
        $codes = array_column($audit['issues'], 'code');

        $this->assertSame('invalid', $audit['integrity_status']);
        $this->assertFalse($audit['healthy']);
        $this->assertContains('terminal_application_has_next_action', $codes);
        $this->assertContains('latest_status_history_mismatch', $codes);
        $this->assertSame(0, $audit['summary']['warnings']);
        $this->assertGreaterThanOrEqual(2, $audit['summary']['errors']);
    }

    public function test_partial_snapshot_and_confirmation_mismatch_are_detected(): void
    {
        [$owner, $application] = $this->scenario();
        $this->confirm($application, $owner);
        $application->forceFill([
            'submitted_document_filename' => 'different-name.md',
            'submitted_document_mime_type' => null,
        ])->save();

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );
        $codes = array_column($audit['issues'], 'code');

        $this->assertContains('submitted_snapshot_incomplete', $codes);
        $this->assertContains('applied_lifecycle_missing_submitted_snapshot', $codes);
        $this->assertContains('submission_confirmation_snapshot_mismatch', $codes);
        $this->assertContains('submitted_document_file_invalid', $codes);
        $this->assertSame('invalid', $audit['integrity_status']);
    }

    public function test_broken_status_history_chain_is_detected(): void
    {
        [$owner, $application] = $this->scenario();
        $this->confirm($application, $owner);
        $application->statusHistory()->create([
            'from_status' => 'assessment',
            'status' => 'screening',
            'changed_by' => $owner->id,
            'changed_at' => '2026-07-08 11:30:00',
        ]);
        $application->forceFill(['status' => 'screening'])->save();

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );

        $this->assertContains(
            'status_history_chain_broken',
            array_column($audit['issues'], 'code'),
        );
        $this->assertNotContains(
            'latest_status_history_mismatch',
            array_column($audit['issues'], 'code'),
        );
    }

    public function test_terminal_application_with_planned_event_is_warning_only(): void
    {
        [$owner, $application] = $this->scenario();
        $this->confirm($application, $owner);
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'event-001',
                'event_type' => 'interview',
                'title' => 'Interview',
                'starts_at' => '2026-07-15 10:00:00',
            ],
        );
        app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'rejected',
                'changed_at' => '2026-07-08 11:30:00',
            ],
        );

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );

        $this->assertSame('warning', $audit['integrity_status']);
        $this->assertFalse($audit['healthy']);
        $this->assertSame(0, $audit['summary']['errors']);
        $this->assertSame(1, $audit['summary']['warnings']);
        $this->assertSame(
            'terminal_application_has_planned_events',
            $audit['issues'][0]['code'],
        );
        $this->assertSame(
            [$event->id],
            $audit['issues'][0]['context']['scheduled_event_ids'],
        );
    }

    public function test_event_and_replacement_corruption_is_detected(): void
    {
        [$owner, $application] = $this->scenario();
        $this->confirm($application, $owner);
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'event-old',
                'event_type' => 'interview',
                'title' => 'Interview',
                'starts_at' => '2026-07-15 10:00:00',
            ],
        );
        app(RescheduleJobApplicationEvent::class)->execute(
            $event,
            $owner,
            [
                'client_reference' => 'reschedule-001',
                'changed_at' => '2026-07-08 12:00:00',
                'replacement_event' => [
                    'client_reference' => 'event-new',
                    'event_type' => 'interview',
                    'title' => 'Interview new time',
                    'starts_at' => '2026-07-16 10:00:00',
                ],
            ],
        );
        $event = $event->fresh();
        $event->forceFill([
            'status' => 'planned',
            'resolved_at' => '2026-07-08 12:00:00',
        ])->save();

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );
        $codes = array_column($audit['issues'], 'code');

        $this->assertContains('planned_event_has_resolution_data', $codes);
        $this->assertContains('scheduled_event_history_mismatch', $codes);
        $this->assertContains('replaced_event_not_cancelled', $codes);
    }

    public function test_tampered_submitted_file_is_detected_without_access_audit(): void
    {
        [$owner, $application, , $path] = $this->scenario();
        $this->confirm($application, $owner);
        Storage::disk('local')->put($path, 'tampered content');

        $audit = app(InspectJobApplicationIntegrity::class)->execute(
            $application,
            $owner,
        );

        $this->assertContains(
            'submitted_document_file_invalid',
            array_column($audit['issues'], 'code'),
        );
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
        $this->assertSame('applied', $application->fresh()->status);
    }

    public function test_audit_is_deterministic_read_only_and_authorized(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(InspectJobApplicationIntegrity::class);
        $before = $this->databaseCounts();

        $first = $action->execute($application, $owner);
        $second = $action->execute($application, $owner);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());

        $this->expectException(AuthorizationException::class);

        $action->execute($application, User::factory()->create());
    }

    private function scenario(): array
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow('2026-07-08 08:00:00');

        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Acme',
            'source' => 'company_site',
            'external_id' => 'JOB-001',
            'source_url' => 'https://jobs.example.com/backend',
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
            'reviewed_at' => '2026-07-08 07:30:00',
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
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $sourceVersion->id,
            'generated_document_version_id' => $version->id,
            'job_title' => $posting->title,
            'company_name' => $posting->company_name,
            'status' => 'draft',
        ]);
        $document->forceFill(['job_application_id' => $application->id])->save();

        CarbonImmutable::setTestNow('2026-07-08 12:00:00');

        return [$owner, $application, $version->fresh(), $path];
    }

    private function confirm(
        JobApplication $application,
        User $owner,
    ): void {
        app(ConfirmJobApplicationManualSubmission::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'submission-001',
                'submitted_at' => '2026-07-08 11:00:00',
                'application_channel' => 'company_website',
                'external_reference' => 'APP-001',
                'destination_url' => 'https://jobs.example.com/backend/apply',
            ],
        );
    }

    private function databaseCounts(): array
    {
        return [
            'applications' => JobApplication::query()->count(),
            'status_histories' => DB::table('job_application_status_histories')->count(),
            'tracking_histories' => DB::table('job_application_tracking_histories')->count(),
            'document_access_histories' => DB::table('job_application_document_access_histories')->count(),
            'submission_confirmations' => DB::table('job_application_submission_confirmations')->count(),
            'scheduled_events' => DB::table('job_application_scheduled_events')->count(),
            'event_histories' => DB::table('job_application_scheduled_event_histories')->count(),
            'event_replacements' => DB::table('job_application_scheduled_event_replacements')->count(),
        ];
    }
}
