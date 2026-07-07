<?php

namespace Tests\Feature\Foundation;

use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\Language;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\Software;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobPostingRequirementFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_posting_can_store_ordered_structured_requirements(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
        ]);
        $skill = Skill::create([
            'name' => 'PHP',
            'normalized_name' => 'php',
        ]);
        $software = Software::create([
            'name' => 'Docker',
            'normalized_name' => 'docker',
        ]);
        $language = Language::create([
            'name' => 'English',
            'code' => 'en',
        ]);

        $docker = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'software_id' => $software->id,
            'requirement_type' => 'software',
            'importance' => 'preferred',
            'label' => 'Docker',
            'normalized_label' => 'docker',
            'source' => 'manual',
            'review_status' => 'approved',
            'position' => 2,
        ]);
        $php = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'skill_id' => $skill->id,
            'requirement_type' => 'skill',
            'importance' => 'required',
            'label' => 'PHP',
            'normalized_label' => 'php',
            'min_years' => 3,
            'source' => 'ai',
            'confidence_bps' => 9200,
            'evidence' => 'At least 3 years of PHP experience.',
            'position' => 1,
        ]);
        $english = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'language_id' => $language->id,
            'requirement_type' => 'language',
            'importance' => 'preferred',
            'label' => 'English',
            'normalized_label' => 'english',
            'proficiency_level' => 'B2',
            'source' => 'manual',
            'review_status' => 'approved',
            'position' => 3,
        ]);

        $this->assertSame(
            [$php->id, $docker->id, $english->id],
            $posting->fresh()->requirements->pluck('id')->all(),
        );
        $this->assertTrue($php->fresh()->jobPosting->is($posting));
        $this->assertTrue($php->fresh()->skill->is($skill));
        $this->assertTrue($docker->fresh()->software->is($software));
        $this->assertTrue($english->fresh()->language->is($language));
        $this->assertSame('3.0', $php->fresh()->min_years);
        $this->assertSame(9200, $php->fresh()->confidence_bps);
        $this->assertSame(1, $php->fresh()->position);
        $this->assertSame('pending', $php->fresh()->review_status);
        $this->assertSame($php->id, $skill->fresh()->jobPostingRequirements->first()->id);
        $this->assertSame($docker->id, $software->fresh()->jobPostingRequirements->first()->id);
        $this->assertSame($english->id, $language->fresh()->jobPostingRequirements->first()->id);
    }

    public function test_deleting_taxonomy_entities_preserves_requirement_evidence(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $skill = Skill::create([
            'name' => 'PHP',
            'normalized_name' => 'php',
        ]);
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'skill_id' => $skill->id,
            'requirement_type' => 'skill',
            'label' => 'PHP',
            'evidence' => 'Strong PHP knowledge is required.',
        ]);

        $skill->delete();
        $requirement->refresh();

        $this->assertNull($requirement->skill_id);
        $this->assertSame('PHP', $requirement->label);
        $this->assertSame('Strong PHP knowledge is required.', $requirement->evidence);
        $this->assertDatabaseHas('job_posting_requirements', ['id' => $requirement->id]);
    }

    public function test_deleting_job_posting_removes_requirements(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'skill',
            'label' => 'PHP',
        ]);

        $posting->delete();

        $this->assertDatabaseMissing('job_posting_requirements', ['id' => $requirement->id]);
    }
}
