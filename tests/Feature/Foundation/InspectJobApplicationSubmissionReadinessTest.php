<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\InspectJobApplicationSubmissionReadiness;
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
use Tests\TestCase;

class InspectJobApplicationSubmissionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_inspect_a_ready_application(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(InspectJobApplicationSubmissionReadiness::class);

        $first = $action->execute($application, $owner);
        $second = $action->execute($application, $owner);

        $this->assertTrue($first['ready']);
        $this->assertSame('ready', $first['status']);
        $this->assertSame($application->id, $first['application_id']);
        $this->assertSame([], $first['blockers']);
        $this->assertSame($first, $second);
    }

    public function test_missing_export_file_is_reported(): void
    {
        [$owner, $application, , , $path] = $this->scenario();
        Storage::disk('local')->delete($path);

        $report = app(InspectJobApplicationSubmissionReadiness::class)
            ->execute($application, $owner);

        $this->assertFalse($report['ready']);
        $this->assertContains('export_file_missing', $this->codes($report));
    }

    public function test_tampered_export_file_is_reported(): void
    {
        [$owner, $application, , , $path] = $this->scenario();
        Storage::disk('local')->put($path, 'tampered export');

        $report = app(InspectJobApplicationSubmissionReadiness::class)
            ->execute($application, $owner);

        $this->assertFalse($report['ready']);
        $this->assertContains('export_file_checksum_mismatch', $this->codes($report));
    }

    public function test_content_changed_after_approval_is_reported(): void
    {
        [$owner, $application, $version] = $this->scenario();
        $version->forceFill(['content' => 'Changed after approval.'])->save();

        $report = app(InspectJobApplicationSubmissionReadiness::class)
            ->execute($application, $owner);
        $codes = $this->codes($report);

        $this->assertFalse($report['ready']);
        $this->assertContains('reviewed_content_mismatch', $codes);
        $this->assertContains('export_checksum_metadata_mismatch', $codes);
        $this->assertContains('export_size_metadata_mismatch', $codes);
    }

    public function test_missing_export_metadata_is_reported(): void
    {
        [$owner, $application, $version] = $this->scenario();
        $version->forceFill([
            'storage_disk' => null,
            'storage_path' => null,
            'filename' => null,
            'mime_type' => null,
            'file_size' => null,
            'checksum_sha256' => null,
        ])->save();

        $report = app(InspectJobApplicationSubmissionReadiness::class)
            ->execute($application, $owner);
        $codes = $this->codes($report);

        $this->assertFalse($report['ready']);
        $this->assertContains('export_disk_not_private', $codes);
        $this->assertContains('export_path_missing', $codes);
    }

    public function test_document_and_source_mismatches_are_reported_together(): void
    {
        [$owner, $application, $version, $document] = $this->scenario();
        $otherProfile = Profile::create(['user_id' => User::factory()->create()->id]);
        $otherResume = Resume::create([
            'profile_id' => $otherProfile->id,
            'name' => 'Other CV',
        ]);
        $otherVersion = ResumeVersion::create([
            'resume_id' => $otherResume->id,
            'version_number' => 1,
            'original_filename' => 'other.pdf',
            'storage_path' => 'resumes/other.pdf',
        ]);
        $document->forceFill(['job_application_id' => null])->save();
        $version->forceFill(['source_resume_version_id' => $otherVersion->id])->save();

        $report = app(InspectJobApplicationSubmissionReadiness::class)
            ->execute($application, $owner);
        $codes = $this->codes($report);

        $this->assertContains('document_application_mismatch', $codes);
        $this->assertContains('source_resume_selection_mismatch', $codes);
        $this->assertContains('source_resume_profile_mismatch', $codes);
    }

    public function test_user_cannot_inspect_another_users_application(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(InspectJobApplicationSubmissionReadiness::class)
            ->execute($application, $outsider);
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
            'reviewed_at' => now()->subHour(),
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

        return [$owner, $application, $version, $document, $path];
    }

    private function codes(array $report): array
    {
        return array_column($report['blockers'], 'code');
    }
}
