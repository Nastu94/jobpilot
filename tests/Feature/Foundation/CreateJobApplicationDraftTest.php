<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\CreateJobApplicationDraft;
use App\Models\Company;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateJobApplicationDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_draft_from_an_approved_targeted_resume(): void
    {
        [$owner, $profile, $posting, $sourceVersion, $document, $version] = $this->scenario();

        $application = app(CreateJobApplicationDraft::class)->execute($version, $owner);

        $this->assertSame($profile->id, $application->profile_id);
        $this->assertSame($posting->id, $application->job_posting_id);
        $this->assertSame($sourceVersion->id, $application->resume_version_id);
        $this->assertSame('Backend Developer', $application->job_title);
        $this->assertSame('Acme', $application->company_name);
        $this->assertSame('draft', $application->status);
        $this->assertNull($application->applied_at);
        $this->assertStringContainsString((string) $version->id, $application->notes);
        $this->assertCount(1, $application->statusHistory);
        $this->assertSame('draft', $application->statusHistory->first()->status);
        $this->assertNotNull($application->statusHistory->first()->changed_at);
        $this->assertSame($application->id, $document->fresh()->job_application_id);
        $this->assertTrue($application->generatedDocuments->contains($document));
    }

    public function test_repeating_the_same_request_returns_the_existing_application(): void
    {
        [$owner, , , , $document, $version] = $this->scenario();
        $action = app(CreateJobApplicationDraft::class);

        $first = $action->execute($version, $owner);
        $second = $action->execute($version, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $document->fresh()->job_application_id);
        $this->assertDatabaseCount('job_applications', 1);
        $this->assertDatabaseCount('job_application_status_histories', 1);
    }

    public function test_company_relation_is_used_when_posting_snapshot_is_empty(): void
    {
        [$owner, , $posting, , , $version] = $this->scenario();
        $company = Company::create(['name' => 'Related Company']);
        $posting->forceFill([
            'company_id' => $company->id,
            'company_name' => null,
        ])->save();

        $application = app(CreateJobApplicationDraft::class)->execute($version, $owner);

        $this->assertSame('Related Company', $application->company_name);
    }

    public function test_user_cannot_create_an_application_from_another_users_document(): void
    {
        [, , , , , $version] = $this->scenario();
        $outsider = User::factory()->create();

        try {
            app(CreateJobApplicationDraft::class)->execute($version, $outsider);

            $this->fail('An outsider created an application draft.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('job_applications', 0);
            $this->assertDatabaseCount('job_application_status_histories', 0);
        }
    }

    public function test_unapproved_or_unsafe_document_version_cannot_create_an_application(): void
    {
        [$owner, , , , $document, $version] = $this->scenario();
        $version->forceFill([
            'review_status' => 'pending',
            'contains_unverified_claims' => true,
        ])->save();
        $document->forceFill(['status' => 'draft'])->save();

        try {
            app(CreateJobApplicationDraft::class)->execute($version, $owner);

            $this->fail('An unsafe document version created an application.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document_version', $exception->errors());
            $this->assertDatabaseCount('job_applications', 0);
        }
    }

    public function test_source_resume_must_belong_to_the_document_profile(): void
    {
        [$owner, , , , , $version] = $this->scenario();
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
        $version->forceFill([
            'source_resume_version_id' => $otherVersion->id,
        ])->save();

        try {
            app(CreateJobApplicationDraft::class)->execute($version, $owner);

            $this->fail('A source resume from another profile was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_resume_version', $exception->errors());
            $this->assertDatabaseCount('job_applications', 0);
        }
    }

    public function test_only_ready_targeted_resume_documents_can_create_an_application(): void
    {
        [$owner, , , , $document, $version] = $this->scenario();
        $document->forceFill([
            'document_type' => 'cover_letter',
            'status' => 'draft',
        ])->save();

        try {
            app(CreateJobApplicationDraft::class)->execute($version, $owner);

            $this->fail('A non-targeted document created an application.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document', $exception->errors());
            $this->assertDatabaseCount('job_applications', 0);
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
            'is_primary' => true,
        ]);
        $sourceVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
            'processing_status' => 'completed',
            'extracted_text' => 'Verified source resume.',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'ready',
        ]);
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'source_resume_version_id' => $sourceVersion->id,
            'version_number' => 1,
            'generation_method' => 'template',
            'content_format' => 'markdown',
            'content' => '# Approved targeted resume',
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
            'reviewed_by' => $owner->id,
            'reviewed_at' => now(),
        ]);

        return [$owner, $profile, $posting, $sourceVersion, $document, $version];
    }
}
