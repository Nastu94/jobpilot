<?php

namespace Tests\Feature\Foundation;

use App\Actions\Documents\CreateTargetedResumeDraft;
use App\Actions\JobPostings\RunJobPostingMatch;
use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\MatchAnalysis;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\Skill;
use App\Models\User;
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateTargetedResumeDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_controlled_draft_without_rewriting_source_claims(): void
    {
        [$owner, $profile, $posting, $analysis, $sourceVersion, $sourceText] = $this->scenario();

        $version = app(CreateTargetedResumeDraft::class)->execute(
            $analysis,
            $sourceVersion,
            $owner,
        );

        $this->assertSame(1, $version->version_number);
        $this->assertSame('template', $version->generation_method);
        $this->assertSame(DeterministicTargetedResumeDraftBuilder::KEY, $version->generator_key);
        $this->assertSame(DeterministicTargetedResumeDraftBuilder::VERSION, $version->generator_version);
        $this->assertSame('markdown', $version->content_format);
        $this->assertSame('pending', $version->review_status);
        $this->assertFalse($version->contains_unverified_claims);
        $this->assertSame(64, strlen($version->input_hash));
        $this->assertStringContainsString(
            "## Source resume (unchanged)\n\n".$sourceText."\n\n---",
            $version->content,
        );
        $this->assertStringContainsString('[MATCHED] PHP', $version->content);
        $this->assertStringContainsString('not part of the final resume', $version->content);
        $this->assertStringContainsString('without rewriting claims', $version->change_summary);

        $document = $version->generatedDocument;
        $this->assertSame($profile->id, $document->profile_id);
        $this->assertSame($posting->id, $document->job_posting_id);
        $this->assertSame('targeted_resume', $document->document_type);
        $this->assertSame('Targeted CV - Backend Developer at Acme', $document->name);
        $this->assertSame('draft', $document->status);
        $this->assertTrue($version->sourceResumeVersion->is($sourceVersion));
        $this->assertTrue($version->matchAnalysis->is($analysis));
    }

    public function test_exact_request_is_idempotent_but_a_new_analysis_creates_a_new_version(): void
    {
        [$owner, , $posting, $analysis, $sourceVersion] = $this->scenario();
        $action = app(CreateTargetedResumeDraft::class);

        $first = $action->execute($analysis, $sourceVersion, $owner);
        $retry = $action->execute($analysis, $sourceVersion, $owner);
        $newAnalysis = app(RunJobPostingMatch::class)->execute($posting, $owner);
        $second = $action->execute($newAnalysis, $sourceVersion, $owner);

        $this->assertSame($first->id, $retry->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $first->version_number);
        $this->assertSame(2, $second->version_number);
        $this->assertSame($first->generated_document_id, $second->generated_document_id);
        $this->assertSame($first->input_hash, $second->input_hash);
        $this->assertSame($first->content, $second->content);
        $this->assertNotSame($first->match_analysis_id, $second->match_analysis_id);
        $this->assertDatabaseCount('generated_documents', 1);
        $this->assertDatabaseCount('generated_document_versions', 2);
    }

    public function test_user_cannot_create_a_draft_for_another_users_analysis(): void
    {
        [, , , $analysis, $sourceVersion] = $this->scenario();
        $outsider = User::factory()->create();

        try {
            app(CreateTargetedResumeDraft::class)->execute(
                $analysis,
                $sourceVersion,
                $outsider,
            );

            $this->fail('An outsider was able to create a targeted resume draft.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('generated_documents', 0);
            $this->assertDatabaseCount('generated_document_versions', 0);
        }
    }

    public function test_source_resume_must_belong_to_the_analysis_profile_and_have_extracted_text(): void
    {
        [$owner, , , $analysis] = $this->scenario();
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
            'processing_status' => 'completed',
            'extracted_text' => 'Other candidate experience.',
        ]);

        try {
            app(CreateTargetedResumeDraft::class)->execute(
                $analysis,
                $otherVersion,
                $owner,
            );

            $this->fail('A resume from another profile was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('generated_documents', 0);
        }
    }

    public function test_analysis_without_scorable_requirements_cannot_create_a_draft(): void
    {
        [$owner, $profile, $posting, , $sourceVersion] = $this->scenario();
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'ruleset_key' => 'approved_requirement_match',
            'ruleset_version' => '1.0.0',
            'status' => 'insufficient_data',
            'input_hash' => str_repeat('a', 64),
        ]);

        try {
            app(CreateTargetedResumeDraft::class)->execute(
                $analysis,
                $sourceVersion,
                $owner,
            );

            $this->fail('An analysis without scorable requirements created a draft.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('generated_documents', 0);
            $this->assertDatabaseCount('generated_document_versions', 0);
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
        $skill = Skill::create([
            'name' => 'PHP',
            'normalized_name' => 'php',
        ]);
        $profile->skills()->attach($skill, [
            'is_approved' => true,
            'years_experience' => 4,
        ]);
        JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'skill_id' => $skill->id,
            'requirement_type' => 'skill',
            'importance' => 'required',
            'label' => 'PHP',
            'min_years' => 3,
            'source' => 'manual',
            'review_status' => 'approved',
            'evidence' => 'At least three years of PHP experience.',
            'position' => 1,
        ]);
        $analysis = app(RunJobPostingMatch::class)->execute($posting, $owner);
        $resume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'Main CV',
            'is_primary' => true,
        ]);
        $sourceText = "Vittorio Soligo\r\nPHP Developer\nBuilt internal APIs.";
        $sourceVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
            'mime_type' => 'application/pdf',
            'checksum_sha256' => hash('sha256', 'source-file'),
            'processing_status' => 'completed',
            'extracted_text' => $sourceText,
        ]);

        return [$owner, $profile, $posting, $analysis, $sourceVersion, $sourceText];
    }
}
