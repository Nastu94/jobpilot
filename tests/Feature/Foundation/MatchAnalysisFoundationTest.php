<?php

namespace Tests\Feature\Foundation;

use App\Models\JobPosting;
use App\Models\MatchAnalysis;
use App\Models\MatchEvidence;
use App\Models\MatchFactor;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchAnalysisFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_can_store_ordered_factors_and_evidence(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $resume = Resume::create(['profile_id' => $profile->id, 'name' => 'Main CV']);
        $version = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $version->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
            'status' => 'completed',
            'score_bps' => 7850,
            'input_hash' => str_repeat('c', 64),
            'calculated_at' => '2026-07-07 14:00:00',
            'summary' => 'Good fit with one relevant skill gap.',
        ]);

        $skills = MatchFactor::create([
            'match_analysis_id' => $analysis->id,
            'key' => 'skills',
            'label' => 'Skills',
            'weight_bps' => 5000,
            'score_bps' => 8000,
            'contribution_bps' => 4000,
            'outcome' => 'partial',
            'position' => 2,
        ]);
        $location = MatchFactor::create([
            'match_analysis_id' => $analysis->id,
            'key' => 'location',
            'label' => 'Location',
            'weight_bps' => 2000,
            'score_bps' => 10000,
            'contribution_bps' => 2000,
            'outcome' => 'matched',
            'position' => 1,
        ]);

        $gap = MatchEvidence::create([
            'match_factor_id' => $skills->id,
            'evidence_type' => 'gap',
            'label' => 'Laravel not explicitly listed',
            'source_type' => 'job_requirement',
            'source_reference' => 'Laravel',
            'position' => 2,
        ]);
        $support = MatchEvidence::create([
            'match_factor_id' => $skills->id,
            'evidence_type' => 'supporting',
            'label' => 'PHP experience found',
            'source_type' => 'profile_skill',
            'source_id' => 42,
            'source_reference' => 'PHP',
            'position' => 1,
        ]);

        $this->assertSame([$location->id, $skills->id], $analysis->fresh()->factors->pluck('id')->all());
        $this->assertSame([$support->id, $gap->id], $skills->fresh()->evidences->pluck('id')->all());
        $this->assertTrue($analysis->fresh()->profile->is($profile));
        $this->assertTrue($analysis->fresh()->jobPosting->is($posting));
        $this->assertTrue($analysis->fresh()->resumeVersion->is($version));
        $this->assertSame(7850, $analysis->fresh()->score_bps);
        $this->assertSame(4000, $skills->fresh()->contribution_bps);
        $this->assertSame('2026-07-07 14:00:00', $analysis->fresh()->calculated_at->format('Y-m-d H:i:s'));
        $this->assertSame($analysis->id, $profile->fresh()->matchAnalyses->first()->id);
        $this->assertSame($analysis->id, $posting->fresh()->matchAnalyses->first()->id);
        $this->assertSame($analysis->id, $version->fresh()->matchAnalyses->first()->id);
    }

    public function test_factor_key_is_unique_within_analysis(): void
    {
        $this->expectException(QueryException::class);

        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
        ]);

        $factor = [
            'match_analysis_id' => $analysis->id,
            'key' => 'skills',
            'label' => 'Skills',
        ];

        MatchFactor::create($factor);
        MatchFactor::create($factor);
    }

    public function test_deleting_resume_version_preserves_analysis(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $resume = Resume::create(['profile_id' => $profile->id, 'name' => 'Main CV']);
        $version = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $version->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
        ]);

        $version->delete();
        $analysis->refresh();

        $this->assertNull($analysis->resume_version_id);
        $this->assertDatabaseHas('match_analyses', ['id' => $analysis->id]);
    }

    public function test_deleting_job_posting_removes_analysis_tree(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
        ]);
        $factor = MatchFactor::create([
            'match_analysis_id' => $analysis->id,
            'key' => 'skills',
            'label' => 'Skills',
        ]);
        $evidence = MatchEvidence::create([
            'match_factor_id' => $factor->id,
            'evidence_type' => 'supporting',
            'label' => 'PHP experience found',
        ]);

        $posting->delete();

        $this->assertDatabaseMissing('match_analyses', ['id' => $analysis->id]);
        $this->assertDatabaseMissing('match_factors', ['id' => $factor->id]);
        $this->assertDatabaseMissing('match_evidences', ['id' => $evidence->id]);
    }
}
