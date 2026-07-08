<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationActionCenter;
use App\Actions\Applications\ConfirmJobApplicationManualSubmission;
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
use App\Services\Applications\JobApplicationActionCenterBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildJobApplicationActionCenterTest extends TestCase
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

    public function test_ready_draft_exposes_submission_and_active_workflow_actions(): void
    {
        [$owner, $application, $version] = $this->scenario();

        $center = app(BuildJobApplicationActionCenter::class)->execute(
            $application,
            $owner,
        );
        $actions = collect($center['actions'])->keyBy('code');

        $this->assertSame($application->id, $center['application_id']);
        $this->assertSame('draft', $center['application_status']);
        $this->assertFalse($center['is_terminal']);
        $this->assertTrue($center['submission_readiness']['ready']);
        $this->assertNull($center['submission_confirmation_id']);
        $this->assertSame([], $center['planned_scheduled_event_ids']);
        $this->assertSame([
            'inspect_submission_handoff',
            'prepare_submission_handoff',
            'confirm_manual_submission',
            'read_application_document',
            'transition_status',
            'record_interaction',
            'schedule_event',
            'resolve_scheduled_event',
            'reschedule_scheduled_event',
        ], $center['action_order']);
        $this->assertSame([
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE => 7,
            JobApplicationActionCenterBuilder::STATUS_BLOCKED => 0,
            JobApplicationActionCenterBuilder::STATUS_COMPLETED => 0,
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE => 2,
            'total' => 9,
        ], $center['summary']);

        foreach ([
            'inspect_submission_handoff',
            'prepare_submission_handoff',
            'confirm_manual_submission',
            'read_application_document',
            'transition_status',
            'record_interaction',
            'schedule_event',
        ] as $code) {
            $this->assertSame(
                JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
                $actions[$code]['status'],
            );
        }

        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE,
            $actions['resolve_scheduled_event']['status'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE,
            $actions['reschedule_scheduled_event']['status'],
        );
        $this->assertSame(
            $version->id,
            $actions['read_application_document']['context']['generated_document_version_id'],
        );
        $this->assertSame(
            'selected_version',
            $actions['read_application_document']['context']['document_source'],
        );
        $this->assertSame(
            ['applied', 'withdrawn'],
            $actions['transition_status']['context']['available_target_statuses'],
        );
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
        $this->assertDatabaseCount('job_application_status_histories', 0);
        $this->assertSame('draft', $application->fresh()->status);
    }

    public function test_blocked_draft_exposes_readiness_reasons_without_hiding_withdrawal(): void
    {
        [$owner, $application] = $this->scenario();
        $application->forceFill([
            'generated_document_version_id' => null,
        ])->save();

        $center = app(BuildJobApplicationActionCenter::class)->execute(
            $application,
            $owner,
        );
        $actions = collect($center['actions'])->keyBy('code');
        $targets = collect(
            $actions['transition_status']['context']['targets'],
        )->keyBy('status');

        $this->assertFalse($center['submission_readiness']['ready']);
        $this->assertSame([
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE => 4,
            JobApplicationActionCenterBuilder::STATUS_BLOCKED => 3,
            JobApplicationActionCenterBuilder::STATUS_COMPLETED => 0,
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE => 2,
            'total' => 9,
        ], $center['summary']);
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
            $actions['inspect_submission_handoff']['status'],
        );

        foreach ([
            'prepare_submission_handoff',
            'confirm_manual_submission',
            'read_application_document',
        ] as $code) {
            $this->assertSame(
                JobApplicationActionCenterBuilder::STATUS_BLOCKED,
                $actions[$code]['status'],
            );
            $this->assertContains(
                'selected_version_missing',
                $actions[$code]['reason_codes'],
            );
        }

        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_BLOCKED,
            $targets['applied']['availability'],
        );
        $this->assertContains(
            'selected_version_missing',
            $targets['applied']['reason_codes'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
            $targets['withdrawn']['availability'],
        );
        $this->assertSame(
            ['withdrawn'],
            $actions['transition_status']['context']['available_target_statuses'],
        );
    }

    public function test_confirmed_application_marks_submission_complete_and_uses_snapshot(): void
    {
        [$owner, $application, $version] = $this->scenario();
        $confirmation = $this->confirm($application, $owner);
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'interview-001',
                'event_type' => 'interview',
                'title' => 'Technical interview',
                'starts_at' => '2026-07-15 10:00:00',
            ],
        );

        $center = app(BuildJobApplicationActionCenter::class)->execute(
            $application,
            $owner,
        );
        $actions = collect($center['actions'])->keyBy('code');

        $this->assertSame('applied', $center['application_status']);
        $this->assertSame($confirmation->id, $center['submission_confirmation_id']);
        $this->assertSame([$event->id], $center['planned_scheduled_event_ids']);
        $this->assertSame([
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE => 6,
            JobApplicationActionCenterBuilder::STATUS_BLOCKED => 0,
            JobApplicationActionCenterBuilder::STATUS_COMPLETED => 1,
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE => 2,
            'total' => 9,
        ], $center['summary']);
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_COMPLETED,
            $actions['confirm_manual_submission']['status'],
        );
        $this->assertSame(
            $confirmation->id,
            $actions['confirm_manual_submission']['context']['submission_confirmation_id'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
            $actions['read_application_document']['status'],
        );
        $this->assertSame(
            'submitted_snapshot',
            $actions['read_application_document']['context']['document_source'],
        );
        $this->assertSame(
            $version->id,
            $actions['read_application_document']['context']['generated_document_version_id'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
            $actions['resolve_scheduled_event']['status'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
            $actions['reschedule_scheduled_event']['status'],
        );
        $this->assertSame(
            [$event->id],
            $actions['resolve_scheduled_event']['context']['scheduled_event_ids'],
        );
        $this->assertSame(
            ['screening', 'assessment', 'interview', 'offer', 'rejected', 'withdrawn'],
            $actions['transition_status']['context']['available_target_statuses'],
        );
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    public function test_terminal_application_keeps_event_resolution_but_blocks_new_or_replacement_events(): void
    {
        [$owner, $application] = $this->scenario();
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'deadline-001',
                'event_type' => 'deadline',
                'title' => 'Respond to recruiter',
                'starts_at' => '2026-07-15 10:00:00',
            ],
        );
        $this->confirm($application, $owner);
        app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'rejected',
                'changed_at' => '2026-07-08 11:30:00',
            ],
        );

        $center = app(BuildJobApplicationActionCenter::class)->execute(
            $application,
            $owner,
        );
        $actions = collect($center['actions'])->keyBy('code');

        $this->assertSame('rejected', $center['application_status']);
        $this->assertTrue($center['is_terminal']);
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE,
            $actions['transition_status']['status'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE,
            $actions['schedule_event']['status'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
            $actions['resolve_scheduled_event']['status'],
        );
        $this->assertSame(
            [$event->id],
            $actions['resolve_scheduled_event']['context']['scheduled_event_ids'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_NOT_APPLICABLE,
            $actions['reschedule_scheduled_event']['status'],
        );
        $this->assertContains(
            'terminal_application',
            $actions['reschedule_scheduled_event']['reason_codes'],
        );
        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_AVAILABLE,
            $actions['record_interaction']['status'],
        );
    }

    public function test_tampered_submitted_file_blocks_document_read_without_creating_audit(): void
    {
        [$owner, $application, , $path] = $this->scenario();
        $this->confirm($application, $owner);
        Storage::disk('local')->put($path, 'tampered content');

        $center = app(BuildJobApplicationActionCenter::class)->execute(
            $application,
            $owner,
        );
        $actions = collect($center['actions'])->keyBy('code');

        $this->assertSame(
            JobApplicationActionCenterBuilder::STATUS_BLOCKED,
            $actions['read_application_document']['status'],
        );
        $this->assertContains(
            'document_unavailable',
            $actions['read_application_document']['reason_codes'],
        );
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
        $this->assertSame('applied', $application->fresh()->status);
    }

    public function test_exact_action_center_request_is_deterministic_and_read_only(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(BuildJobApplicationActionCenter::class);
        $before = $this->databaseCounts();

        $first = $action->execute($application, $owner);
        $second = $action->execute($application, $owner);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_outsider_cannot_build_application_action_center(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(BuildJobApplicationActionCenter::class)->execute(
            $application,
            $outsider,
        );
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

        return [
            $owner,
            $application,
            $version->fresh(),
            $path,
        ];
    }

    private function confirm(
        JobApplication $application,
        User $owner,
    ) {
        return app(ConfirmJobApplicationManualSubmission::class)->execute(
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
            'status_histories' => \DB::table('job_application_status_histories')->count(),
            'tracking_histories' => \DB::table('job_application_tracking_histories')->count(),
            'document_access_histories' => \DB::table('job_application_document_access_histories')->count(),
            'submission_confirmations' => \DB::table('job_application_submission_confirmations')->count(),
            'scheduled_events' => \DB::table('job_application_scheduled_events')->count(),
            'interactions' => \DB::table('job_application_interactions')->count(),
        ];
    }
}
