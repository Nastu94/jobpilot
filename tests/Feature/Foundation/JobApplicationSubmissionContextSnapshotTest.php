<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\ReadJobApplicationSubmissionContextSnapshot;
use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JobApplicationSubmissionContextSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_applied_transition_captures_application_and_job_context(): void
    {
        [$owner, $application, $posting] = $this->scenario();
        $changedAt = now()->subHour()->startOfSecond();

        $applied = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => $changedAt->toDateTimeString(),
                'application_channel' => '  Company website  ',
                'external_reference' => '  APP-123  ',
            ],
        );

        $this->assertSame(
            $changedAt->toDateTimeString(),
            $applied->submitted_context_captured_at->toDateTimeString(),
        );
        $this->assertSame($posting->id, $applied->submitted_job_posting_id);
        $this->assertSame('Backend Developer', $applied->submitted_job_title);
        $this->assertSame('Acme', $applied->submitted_company_name);
        $this->assertSame('linkedin', $applied->submitted_job_source);
        $this->assertSame('Rome', $applied->submitted_job_location);
        $this->assertSame('IT', $applied->submitted_job_country_code);
        $this->assertSame('hybrid', $applied->submitted_job_remote_type);
        $this->assertSame('full_time', $applied->submitted_job_employment_type);
        $this->assertSame('mid', $applied->submitted_job_seniority);
        $this->assertSame('Company website', $applied->submitted_application_channel);
        $this->assertSame('APP-123', $applied->submitted_external_reference);
        $this->assertSame('Company website', $applied->application_channel);
        $this->assertSame('APP-123', $applied->external_reference);
    }

    public function test_snapshot_remains_unchanged_after_live_context_changes(): void
    {
        [$owner, $application, $posting] = $this->scenario();
        $action = app(TransitionJobApplicationStatus::class);
        $applied = $action->execute($application, $owner, [
            'status' => 'applied',
            'changed_at' => now()->subHour()->toDateTimeString(),
            'application_channel' => 'Company website',
            'external_reference' => 'APP-123',
        ]);
        $snapshot = $this->snapshot($applied);

        $posting->forceFill([
            'source' => 'referral',
            'location' => 'Milan',
            'country_code' => 'CH',
            'remote_type' => 'remote',
            'employment_type' => 'contract',
            'seniority' => 'senior',
        ])->save();
        $applied->forceFill([
            'job_title' => 'Lead Developer',
            'company_name' => 'Acme Updated',
            'application_channel' => 'Recruiter',
            'external_reference' => 'APP-999',
        ])->save();

        $interview = $action->execute($applied, $owner, [
            'status' => 'interview',
            'changed_at' => now()->subMinutes(30)->toDateTimeString(),
        ]);

        $this->assertSame($snapshot, $this->snapshot($interview));
        $this->assertSame('Lead Developer', $interview->job_title);
        $this->assertSame('Acme Updated', $interview->company_name);
        $this->assertSame('Recruiter', $interview->application_channel);
        $this->assertSame('APP-999', $interview->external_reference);
        $this->assertSame('referral', $posting->fresh()->source);
    }

    public function test_submission_without_linked_posting_captures_nullable_job_attributes(): void
    {
        [$owner, $application] = $this->scenario(false);

        $applied = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => now()->subHour()->toDateTimeString(),
                'application_channel' => 'Email',
            ],
        );

        $this->assertNotNull($applied->submitted_context_captured_at);
        $this->assertNull($applied->submitted_job_posting_id);
        $this->assertSame('Backend Developer', $applied->submitted_job_title);
        $this->assertSame('Acme', $applied->submitted_company_name);
        $this->assertNull($applied->submitted_job_source);
        $this->assertNull($applied->submitted_job_location);
        $this->assertNull($applied->submitted_job_country_code);
        $this->assertNull($applied->submitted_job_remote_type);
        $this->assertNull($applied->submitted_job_employment_type);
        $this->assertNull($applied->submitted_job_seniority);
        $this->assertSame('Email', $applied->submitted_application_channel);
        $this->assertNull($applied->submitted_external_reference);
    }

    public function test_withdrawal_before_submission_does_not_capture_context(): void
    {
        [$owner, $application] = $this->scenario();

        $withdrawn = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'withdrawn',
                'changed_at' => now()->subHour()->toDateTimeString(),
            ],
        );

        $this->assertNull($withdrawn->applied_at);
        $this->assertNull($withdrawn->submitted_context_captured_at);
        $this->assertNull($withdrawn->submitted_job_posting_id);
        $this->assertNull($withdrawn->submitted_job_title);
        $this->assertNull($withdrawn->submitted_company_name);
        $this->assertNull($withdrawn->submitted_application_channel);
    }

    public function test_read_model_distinguishes_captured_legacy_and_not_submitted_states(): void
    {
        [$owner, $application] = $this->scenario();
        $applied = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => now()->subHour()->startOfSecond()->toDateTimeString(),
            ],
        );
        $legacy = JobApplication::create([
            'profile_id' => $application->profile_id,
            'job_title' => 'Legacy role',
            'company_name' => 'Legacy company',
            'status' => 'applied',
            'applied_at' => now()->subDays(2),
        ]);
        $draft = JobApplication::create([
            'profile_id' => $application->profile_id,
            'job_title' => 'Draft role',
            'company_name' => 'Draft company',
            'status' => 'draft',
        ]);
        $before = $this->databaseCounts();
        $reader = app(ReadJobApplicationSubmissionContextSnapshot::class);

        $capturedResult = $reader->execute($applied, $owner);
        $legacyResult = $reader->execute($legacy, $owner);
        $draftResult = $reader->execute($draft, $owner);

        $this->assertSame('captured', $capturedResult['availability']);
        $this->assertNotNull($capturedResult['captured_at']);
        $this->assertSame('Backend Developer', $capturedResult['snapshot']['job_title']);
        $this->assertSame('legacy_or_missing', $legacyResult['availability']);
        $this->assertNull($legacyResult['captured_at']);
        $this->assertNull($legacyResult['snapshot']['job_title']);
        $this->assertSame('not_submitted', $draftResult['availability']);
        $this->assertNull($draftResult['applied_at']);
        $this->assertSame($before, $this->databaseCounts());
    }

    public function test_outsider_cannot_read_submission_context_snapshot(): void
    {
        [, $application] = $this->scenario();

        $this->expectException(AuthorizationException::class);

        app(ReadJobApplicationSubmissionContextSnapshot::class)->execute(
            $application,
            User::factory()->create(),
        );
    }

    private function scenario(bool $withPosting = true): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = $withPosting
            ? JobPosting::create([
                'profile_id' => $profile->id,
                'title' => 'Backend Developer',
                'company_name' => 'Acme',
                'source' => 'linkedin',
                'location' => 'Rome',
                'country_code' => 'IT',
                'remote_type' => 'hybrid',
                'employment_type' => 'full_time',
                'seniority' => 'mid',
            ])
            : null;
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
            'job_posting_id' => $posting?->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'ready',
        ]);
        $content = '# Approved targeted resume';
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
            'reviewed_at' => now()->subHours(2),
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
            'job_posting_id' => $posting?->id,
            'resume_version_id' => $sourceVersion->id,
            'generated_document_version_id' => $version->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'draft',
        ]);
        $document->forceFill(['job_application_id' => $application->id])->save();
        JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'from_status' => null,
            'status' => 'draft',
            'changed_by' => $owner->id,
            'changed_at' => now()->subHours(2),
            'notes' => 'Draft created.',
        ]);

        return [$owner, $application, $posting];
    }

    private function snapshot(JobApplication $application): array
    {
        return [
            'captured_at' => $application->submitted_context_captured_at?->toISOString(),
            'job_posting_id' => $application->submitted_job_posting_id,
            'job_title' => $application->submitted_job_title,
            'company_name' => $application->submitted_company_name,
            'job_source' => $application->submitted_job_source,
            'job_location' => $application->submitted_job_location,
            'job_country_code' => $application->submitted_job_country_code,
            'job_remote_type' => $application->submitted_job_remote_type,
            'job_employment_type' => $application->submitted_job_employment_type,
            'job_seniority' => $application->submitted_job_seniority,
            'application_channel' => $application->submitted_application_channel,
            'external_reference' => $application->submitted_external_reference,
        ];
    }

    private function databaseCounts(): array
    {
        return [
            'applications' => JobApplication::query()->count(),
            'status_histories' => DB::table('job_application_status_histories')->count(),
            'tracking_histories' => DB::table('job_application_tracking_histories')->count(),
            'document_access_histories' => DB::table('job_application_document_access_histories')->count(),
        ];
    }
}
