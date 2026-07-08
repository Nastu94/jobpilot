<?php

namespace Tests\Feature\Foundation;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JobApplicationSubmissionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_applied_transition_freezes_the_exact_submitted_document_metadata(): void
    {
        [$owner, $application, $version, , $content, $path] = $this->scenario();

        $applied = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => now()->subMinute()->startOfSecond()->toDateTimeString(),
            ],
        );

        $this->assertSame($version->id, $applied->submitted_generated_document_version_id);
        $this->assertSame($version->source_resume_version_id, $applied->submitted_source_resume_version_id);
        $this->assertSame($version->version_number, $applied->submitted_document_version_number);
        $this->assertSame('targeted-cv-v1.md', $applied->submitted_document_filename);
        $this->assertSame('text/markdown', $applied->submitted_document_mime_type);
        $this->assertSame(strlen($content), $applied->submitted_document_file_size);
        $this->assertSame(hash('sha256', $content), $applied->submitted_document_checksum_sha256);
        $this->assertSame(hash('sha256', $content), $applied->submitted_document_content_sha256);
        $this->assertSame('local', $applied->submitted_document_storage_disk);
        $this->assertSame($path, $applied->submitted_document_storage_path);
        $this->assertSame('manual_targeted_resume_finalization', $applied->submitted_document_generator_key);
        $this->assertSame('1.0.0', $applied->submitted_document_generator_version);
        $this->assertSame(
            $version->reviewed_at->toDateTimeString(),
            $applied->submitted_document_reviewed_at->toDateTimeString(),
        );
    }

    public function test_snapshot_survives_selected_version_deletion(): void
    {
        [$owner, $application, $version] = $this->scenario();
        $applied = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            ['status' => 'applied'],
        );
        $versionId = $version->id;
        $checksum = $applied->submitted_document_checksum_sha256;
        $filename = $applied->submitted_document_filename;

        $version->delete();
        $preserved = $applied->fresh();

        $this->assertNull($preserved->generated_document_version_id);
        $this->assertNull($preserved->generatedDocumentVersion);
        $this->assertSame($versionId, $preserved->submitted_generated_document_version_id);
        $this->assertSame($checksum, $preserved->submitted_document_checksum_sha256);
        $this->assertSame($filename, $preserved->submitted_document_filename);
        $this->assertSame('applied', $preserved->status);
    }

    public function test_later_pipeline_changes_do_not_rewrite_the_submission_snapshot(): void
    {
        [$owner, $application, $version] = $this->scenario();
        $action = app(TransitionJobApplicationStatus::class);
        $applied = $action->execute($application, $owner, ['status' => 'applied']);
        $snapshot = $this->snapshot($applied);

        $version->forceFill([
            'filename' => 'changed-after-submission.txt',
            'mime_type' => 'text/plain',
            'file_size' => 999,
            'checksum_sha256' => str_repeat('f', 64),
            'storage_path' => 'changed/path.txt',
        ])->save();
        $screening = $action->execute($applied, $owner, ['status' => 'screening']);

        $this->assertSame('screening', $screening->status);
        $this->assertSame($snapshot, $this->snapshot($screening));
    }

    public function test_withdrawing_a_draft_does_not_create_a_submission_snapshot(): void
    {
        [$owner, $application] = $this->scenario();

        $withdrawn = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            ['status' => 'withdrawn'],
        );

        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertNull($withdrawn->applied_at);
        $this->assertNull($withdrawn->submitted_generated_document_version_id);
        $this->assertNull($withdrawn->submitted_document_checksum_sha256);
        $this->assertNull($withdrawn->submitted_document_storage_path);
    }

    public function test_submission_snapshot_is_guarded_from_mass_assignment(): void
    {
        [$owner, $application] = $this->scenario();
        $applied = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            ['status' => 'applied'],
        );
        $checksum = $applied->submitted_document_checksum_sha256;
        $path = $applied->submitted_document_storage_path;

        $applied->update([
            'submitted_document_checksum_sha256' => str_repeat('0', 64),
            'submitted_document_storage_path' => 'overwritten/path.txt',
        ]);

        $this->assertSame($checksum, $applied->fresh()->submitted_document_checksum_sha256);
        $this->assertSame($path, $applied->fresh()->submitted_document_storage_path);
    }

    private function scenario(): array
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Acme',
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
            'reviewed_at' => now()->subHour()->startOfSecond(),
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
        JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'from_status' => null,
            'status' => 'draft',
            'changed_by' => $owner->id,
            'changed_at' => now()->subHours(2),
        ]);

        return [$owner, $application, $version, $document, $content, $path];
    }

    private function snapshot(JobApplication $application): array
    {
        $snapshot = $application->only([
            'submitted_generated_document_version_id',
            'submitted_source_resume_version_id',
            'submitted_document_version_number',
            'submitted_document_filename',
            'submitted_document_mime_type',
            'submitted_document_file_size',
            'submitted_document_checksum_sha256',
            'submitted_document_content_sha256',
            'submitted_document_storage_disk',
            'submitted_document_storage_path',
            'submitted_document_generator_key',
            'submitted_document_generator_version',
        ]);
        $snapshot['submitted_document_reviewed_at'] = $application
            ->submitted_document_reviewed_at
            ?->toISOString();

        return $snapshot;
    }
}
