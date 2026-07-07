<?php

namespace Tests\Feature\Foundation;

use App\Actions\JobPostings\RunJobPostingMatch;
use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunJobPostingMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_run_matching_through_the_application_entry_action(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
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

        $this->assertSame('completed', $analysis->status);
        $this->assertSame(10000, $analysis->score_bps);
        $this->assertSame($owner->id, $analysis->profile->user_id);
        $this->assertTrue($analysis->jobPosting->is($posting));
        $this->assertCount(1, $analysis->factors);
        $this->assertSame('matched', $analysis->factors->first()->outcome);
        $this->assertDatabaseHas('match_analyses', [
            'id' => $analysis->id,
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
        ]);
    }

    public function test_user_cannot_run_matching_for_another_users_posting(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);

        try {
            app(RunJobPostingMatch::class)->execute($posting, $outsider);

            $this->fail('An outsider was able to run matching.');
        } catch (AuthorizationException) {
            $this->assertNotSame($owner->id, $outsider->id);
            $this->assertDatabaseCount('match_analyses', 0);
        }
    }

    public function test_entry_action_uses_current_persisted_posting_ownership(): void
    {
        $originalOwner = User::factory()->create();
        $newOwner = User::factory()->create();
        $originalProfile = Profile::create(['user_id' => $originalOwner->id]);
        $newProfile = Profile::create(['user_id' => $newOwner->id]);
        $posting = JobPosting::create([
            'profile_id' => $originalProfile->id,
            'title' => 'Backend Developer',
        ]);
        $stalePosting = $posting->replicate();
        $stalePosting->setRawAttributes($posting->getAttributes(), true);
        $posting->forceFill(['profile_id' => $newProfile->id])->save();

        try {
            app(RunJobPostingMatch::class)->execute($stalePosting, $originalOwner);

            $this->fail('Stale ownership data was trusted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('match_analyses', 0);
        }
    }
}
