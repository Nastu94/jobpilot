<?php

namespace Tests\Feature\Foundation;

use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\MatchAnalysis;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneratedDocumentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_generated_documents_with_ordered_versions(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $resume = Resume::create(['profile_id' => $profile->id, 'name' => 'Main CV']);
        $resumeVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
            'company_name' => 'Acme',
        ]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $resumeVersion->id,
            'job_title' => 'Laravel Developer',
            'company_name' => 'Acme',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $resumeVersion->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
        ]);

        $olderDocument = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'document_type' => 'cover_letter',
            'name' => 'General cover letter',
        ]);
        $newerDocument = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'job_application_id' => $application->id,
            'document_type' => 'targeted_resume',
            'name' => 'CV for Acme',
            'status' => 'ready',
        ]);

        $firstVersion = GeneratedDocumentVersion::create([
            'generated_document_id' => $newerDocument->id,
            'source_resume_version_id' => $resumeVersion->id,
            'match_analysis_id' => $analysis->id,
            'version_number' => 1,
            'generation_method' => 'template',
            'generator_key' => 'targeted_resume_builder',
            'generator_version' => '1.0.0',
            'input_hash' => str_repeat('d', 64),
            'content_format' => 'markdown',
            'content' => 'First generated version.',
            'review_status' => 'pending',
        ]);
        $secondVersion = GeneratedDocumentVersion::create([
            'generated_document_id' => $newerDocument->id,
            'source_resume_version_id' => $resumeVersion->id,
            'match_analysis_id' => $analysis->id,
            'version_number' => 2,
            'generation_method' => 'assisted',
            'generator_key' => 'targeted_resume_builder',
            'generator_version' => '1.1.0',
            'content_format' => 'docx',
            'storage_path' => 'generated/cv-acme-v2.docx',
            'filename' => 'cv-acme-v2.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => 180000,
            'checksum_sha256' => str_repeat('e', 64),
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
            'reviewed_at' => '2026-07-07 15:00:00',
            'change_summary' => 'Improved ordering without adding new claims.',
        ]);

        $this->assertSame([$newerDocument->id, $olderDocument->id], $profile->fresh()->generatedDocuments->pluck('id')->all());
        $this->assertSame([$secondVersion->id, $firstVersion->id], $newerDocument->fresh()->versions->pluck('id')->all());
        $this->assertTrue($newerDocument->fresh()->jobPosting->is($posting));
        $this->assertTrue($newerDocument->fresh()->jobApplication->is($application));
        $this->assertTrue($secondVersion->fresh()->sourceResumeVersion->is($resumeVersion));
        $this->assertTrue($secondVersion->fresh()->matchAnalysis->is($analysis));
        $this->assertFalse($secondVersion->fresh()->contains_unverified_claims);
        $this->assertSame(180000, $secondVersion->fresh()->file_size);
        $this->assertSame('2026-07-07 15:00:00', $secondVersion->fresh()->reviewed_at->format('Y-m-d H:i:s'));
        $this->assertSame($newerDocument->id, $posting->fresh()->generatedDocuments->first()->id);
        $this->assertSame($newerDocument->id, $application->fresh()->generatedDocuments->first()->id);
        $this->assertSame($secondVersion->id, $resumeVersion->fresh()->generatedDocumentVersions->first()->id);
        $this->assertSame($secondVersion->id, $analysis->fresh()->generatedDocumentVersions->first()->id);
    }

    public function test_version_number_is_unique_within_generated_document(): void
    {
        $this->expectException(QueryException::class);

        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'document_type' => 'cover_letter',
            'name' => 'Cover letter',
        ]);
        $version = [
            'generated_document_id' => $document->id,
            'version_number' => 1,
        ];

        GeneratedDocumentVersion::create($version);
        GeneratedDocumentVersion::create($version);
    }

    public function test_deleting_sources_preserves_generated_document_and_version(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $resume = Resume::create(['profile_id' => $profile->id, 'name' => 'Main CV']);
        $resumeVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $resumeVersion->id,
            'job_title' => 'Backend Developer',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $resumeVersion->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'job_application_id' => $application->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
        ]);
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'source_resume_version_id' => $resumeVersion->id,
            'match_analysis_id' => $analysis->id,
            'version_number' => 1,
        ]);

        $posting->delete();
        $resumeVersion->delete();
        $application->delete();
        $document->refresh();
        $version->refresh();

        $this->assertNull($document->job_posting_id);
        $this->assertNull($document->job_application_id);
        $this->assertNull($version->source_resume_version_id);
        $this->assertNull($version->match_analysis_id);
        $this->assertDatabaseHas('generated_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('generated_document_versions', ['id' => $version->id]);
    }

    public function test_deleting_profile_removes_generated_document_tree(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'document_type' => 'cover_letter',
            'name' => 'Cover letter',
        ]);
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'version_number' => 1,
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('generated_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('generated_document_versions', ['id' => $version->id]);
    }
}
