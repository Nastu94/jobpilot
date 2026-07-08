<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\ReadJobApplicationDocumentFile;
use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReadJobApplicationDocumentFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_current_safe_draft_document_and_access_is_audited(): void
    {
        [$owner, $application, $version, $content, $path] = $this->scenario();

        $file = app(ReadJobApplicationDocumentFile::class)->execute(
            $application,
            $owner,
        );

        $this->assertSame($application->id, $file['application_id']);
        $this->assertSame('selected_version', $file['document_source']);
        $this->assertSame($version->id, $file['generated_document_version_id']);
        $this->assertSame($version->source_resume_version_id, $file['source_resume_version_id']);
        $this->assertSame('targeted-cv-v1.md', $file['filename']);
        $this->assertSame('text/markdown', $file['mime_type']);
        $this->assertSame(strlen($content), $file['file_size']);
        $this->assertSame(hash('sha256', $content), $file['checksum_sha256']);
        $this->assertSame($path, $file['storage_path']);
        $this->assertSame($content, $file['contents']);
        $this->assertIsInt($file['access_history_id']);
        $this->assertNotNull($file['accessed_at']);

        $this->assertDatabaseHas('job_application_document_access_histories', [
            'job_application_id' => $application->id,
            'accessed_by' => $owner->id,
            'document_source' => 'selected_version',
            'generated_document_version_id' => $version->id,
            'source_resume_version_id' => $version->source_resume_version_id,
            'filename' => 'targeted-cv-v1.md',
            'file_size' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'storage_disk' => 'local',
            'storage_path' => $path,
        ]);
    }

    public function test_submitted_application_uses_frozen_snapshot_instead_of_current_version_metadata(): void
    {
        [$owner, $application, $version, $content, $path] = $this->scenario();
        $applied = $this->apply($application, $owner);
        $changedContent = '# Changed after submission';
        $changedPath = sprintf(
            'generated-documents/profile-%d/document-%d/version-%d/changed.md',
            $application->profile_id,
            $version->generated_document_id,
            $version->id,
        );
        Storage::disk('local')->put($changedPath, $changedContent);
        $version->forceFill([
            'content' => $changedContent,
            'filename' => 'changed.md',
            'mime_type' => 'text/plain',
            'file_size' => strlen($changedContent),
            'checksum_sha256' => hash('sha256', $changedContent),
            'storage_path' => $changedPath,
        ])->save();

        $file = app(ReadJobApplicationDocumentFile::class)->execute(
            $applied,
            $owner,
        );

        $this->assertSame('submitted_snapshot', $file['document_source']);
        $this->assertSame('targeted-cv-v1.md', $file['filename']);
        $this->assertSame($path, $file['storage_path']);
        $this->assertSame($content, $file['contents']);
        $this->assertSame(hash('sha256', $content), $file['checksum_sha256']);
    }

    public function test_submitted_snapshot_can_be_read_after_selected_version_is_deleted(): void
    {
        [$owner, $application, $version, $content] = $this->scenario();
        $applied = $this->apply($application, $owner);
        $versionId = $version->id;

        $version->delete();
        $file = app(ReadJobApplicationDocumentFile::class)->execute(
            $applied->fresh(),
            $owner,
        );

        $this->assertNull($applied->fresh()->generated_document_version_id);
        $this->assertSame('submitted_snapshot', $file['document_source']);
        $this->assertSame($versionId, $file['generated_document_version_id']);
        $this->assertSame($content, $file['contents']);
        $this->assertDatabaseCount('job_application_document_access_histories', 1);
    }

    public function test_tampered_draft_export_is_rejected_without_access_audit(): void
    {
        [$owner, $application, , , $path] = $this->scenario();
        Storage::disk('local')->put($path, 'tampered draft file');

        try {
            app(ReadJobApplicationDocumentFile::class)->execute(
                $application,
                $owner,
            );

            $this->fail('A tampered draft document was returned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_file', $exception->errors());
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_tampered_submitted_file_is_rejected_without_access_audit(): void
    {
        [$owner, $application, , , $path] = $this->scenario();
        $applied = $this->apply($application, $owner);
        Storage::disk('local')->put($path, 'tampered submitted file');

        try {
            app(ReadJobApplicationDocumentFile::class)->execute(
                $applied,
                $owner,
            );

            $this->fail('A tampered submitted document was returned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_file', $exception->errors());
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_non_draft_application_without_submission_snapshot_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();
        $application->forceFill(['status' => 'applied'])->save();

        try {
            app(ReadJobApplicationDocumentFile::class)->execute(
                $application,
                $owner,
            );

            $this->fail('An application without a submitted snapshot returned a file.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'The application has no complete submitted document snapshot.',
                $exception->errors()['document_file'] ?? [],
            );
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_submitted_snapshot_path_must_remain_inside_expected_private_location(): void
    {
        [$owner, $application] = $this->scenario();
        $applied = $this->apply($application, $owner);
        $unsafePath = 'generated-documents/profile-'.$application->profile_id.'/../outside.md';
        $applied->forceFill([
            'submitted_document_storage_path' => $unsafePath,
        ])->save();

        try {
            app(ReadJobApplicationDocumentFile::class)->execute(
                $applied,
                $owner,
            );

            $this->fail('A submitted snapshot outside the expected path was returned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_file', $exception->errors());
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_user_cannot_read_another_users_application_document(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        try {
            app(ReadJobApplicationDocumentFile::class)->execute(
                $application,
                $outsider,
            );

            $this->fail('An outsider read a private application document.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_each_successful_read_is_recorded_and_history_cascades_with_application(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(ReadJobApplicationDocumentFile::class);

        $action->execute($application, $owner);
        $action->execute($application, $owner);

        $this->assertDatabaseCount('job_application_document_access_histories', 2);
        $this->assertCount(2, $application->fresh()->documentAccessHistory);

        $application->delete();

        $this->assertDatabaseCount('job_application_document_access_histories', 0);
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

        return [$owner, $application, $version->fresh(), $content, $path];
    }

    private function apply(JobApplication $application, User $owner): JobApplication
    {
        return app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => now()->subMinute()->startOfSecond()->toDateTimeString(),
            ],
        );
    }
}
