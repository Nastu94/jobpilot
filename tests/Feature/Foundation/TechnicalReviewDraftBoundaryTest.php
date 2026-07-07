<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\CreateJobApplicationDraft;
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
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TechnicalReviewDraftBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_review_draft_cannot_create_an_application(): void
    {
        [$owner, , $version] = $this->scenario();

        try {
            app(CreateJobApplicationDraft::class)->execute($version, $owner);
            $this->fail('A technical review draft created an application.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document_version', $exception->errors());
            $this->assertDatabaseCount('job_applications', 0);
        }
    }

    public function test_existing_application_with_technical_draft_cannot_be_marked_applied(): void
    {
        [$owner, $document, $version, $posting, $sourceVersion] = $this->scenario();
        $application = JobApplication::create([
            'profile_id' => $document->profile_id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $sourceVersion->id,
            'generated_document_version_id' => $version->id,
            'job_title' => $posting->title,
            'company_name' => $posting->company_name,
            'status' => 'draft',
        ]);
        JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'status' => 'draft',
            'changed_by' => $owner->id,
            'changed_at' => now()->subMinute(),
        ]);

        try {
            app(TransitionJobApplicationStatus::class)->execute(
                $application,
                $owner,
                ['status' => 'applied'],
            );
            $this->fail('A technical review draft was marked as submitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document_version', $exception->errors());
            $this->assertSame('draft', $application->fresh()->status);
        }
    }

    private function scenario(): array
    {
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
        $content = '# Technical review draft';
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'source_resume_version_id' => $sourceVersion->id,
            'version_number' => 1,
            'generation_method' => 'template',
            'generator_key' => DeterministicTargetedResumeDraftBuilder::KEY,
            'generator_version' => DeterministicTargetedResumeDraftBuilder::VERSION,
            'content_format' => 'markdown',
            'content' => $content,
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
            'reviewed_content_sha256' => hash('sha256', $content),
        ]);

        return [$owner, $document, $version, $posting, $sourceVersion];
    }
}
