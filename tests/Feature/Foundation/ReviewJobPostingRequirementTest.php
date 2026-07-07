<?php

namespace Tests\Feature\Foundation;

use App\Actions\JobPostings\ReviewJobPostingRequirement;
use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\Software;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReviewJobPostingRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_approve_correct_and_link_an_ai_requirement(): void
    {
        [$user, $posting] = $this->postingOwnedByUser();
        $skill = Skill::create([
            'name' => 'Laravel',
            'normalized_name' => 'laravel',
        ]);
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'skill',
            'importance' => 'required',
            'label' => 'PHP',
            'normalized_label' => 'php',
            'proficiency_level' => 'intermediate',
            'min_years' => 3,
            'source' => 'ai',
            'review_status' => 'pending',
            'confidence_bps' => 9300,
            'evidence' => 'At least 3 years of PHP experience.',
        ]);

        $reviewed = app(ReviewJobPostingRequirement::class)->execute(
            requirement: $requirement,
            reviewer: $user,
            input: [
                'decision' => 'approved',
                'importance' => 'preferred',
                'label' => 'Laravel',
                'proficiency_level' => 'advanced',
                'min_years' => 4.5,
                'skill_id' => $skill->id,
                'review_notes' => 'Verified against the original posting.',
            ],
        );

        $this->assertSame('approved', $reviewed->review_status);
        $this->assertSame('skill', $reviewed->requirement_type);
        $this->assertSame('preferred', $reviewed->importance);
        $this->assertSame('Laravel', $reviewed->label);
        $this->assertSame('laravel', $reviewed->normalized_label);
        $this->assertSame('advanced', $reviewed->proficiency_level);
        $this->assertSame('4.5', $reviewed->min_years);
        $this->assertTrue($reviewed->skill->is($skill));
        $this->assertTrue($reviewed->reviewedBy->is($user));
        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertSame('Verified against the original posting.', $reviewed->review_notes);

        $this->assertSame('skill', $reviewed->proposed_requirement_type);
        $this->assertSame('required', $reviewed->proposed_importance);
        $this->assertSame('PHP', $reviewed->proposed_label);
        $this->assertSame('php', $reviewed->proposed_normalized_label);
        $this->assertSame('intermediate', $reviewed->proposed_proficiency_level);
        $this->assertSame('3.0', $reviewed->proposed_min_years);
        $this->assertSame('At least 3 years of PHP experience.', $reviewed->evidence);
        $this->assertSame(
            [$requirement->id],
            $posting->fresh()->approvedRequirements->pluck('id')->all(),
        );
    }

    public function test_owner_can_reject_a_requirement_without_exposing_it_to_matching(): void
    {
        [$user, $posting] = $this->postingOwnedByUser();
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'software',
            'importance' => 'required',
            'label' => 'Legacy internal tool',
            'normalized_label' => 'legacy internal tool',
            'source' => 'ai',
            'review_status' => 'pending',
            'evidence' => 'Experience with our legacy internal tool.',
        ]);

        $reviewed = app(ReviewJobPostingRequirement::class)->execute(
            requirement: $requirement,
            reviewer: $user,
            input: [
                'decision' => 'rejected',
                'review_notes' => 'Not a reusable software requirement.',
            ],
        );

        $this->assertSame('rejected', $reviewed->review_status);
        $this->assertSame('Legacy internal tool', $reviewed->proposed_label);
        $this->assertSame('Not a reusable software requirement.', $reviewed->review_notes);
        $this->assertNull($reviewed->skill_id);
        $this->assertNull($reviewed->software_id);
        $this->assertNull($reviewed->language_id);
        $this->assertCount(0, $posting->fresh()->approvedRequirements);
    }

    public function test_rejected_requirement_cannot_be_linked_to_a_taxonomy_entry(): void
    {
        [$user, $posting] = $this->postingOwnedByUser();
        $skill = Skill::create([
            'name' => 'PHP',
            'normalized_name' => 'php',
        ]);
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'skill',
            'label' => 'PHP',
            'source' => 'ai',
            'review_status' => 'pending',
        ]);

        try {
            app(ReviewJobPostingRequirement::class)->execute(
                requirement: $requirement,
                reviewer: $user,
                input: [
                    'decision' => 'rejected',
                    'skill_id' => $skill->id,
                ],
            );

            $this->fail('A rejected requirement was linked to a taxonomy entry.');
        } catch (ValidationException) {
            $requirement->refresh();

            $this->assertSame('pending', $requirement->review_status);
            $this->assertNull($requirement->skill_id);
            $this->assertNull($requirement->reviewed_at);
        }
    }

    public function test_review_rejects_incoherent_taxonomy_associations(): void
    {
        [$user, $posting] = $this->postingOwnedByUser();
        $software = Software::create([
            'name' => 'Docker',
            'normalized_name' => 'docker',
        ]);
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'skill',
            'label' => 'PHP',
            'source' => 'ai',
            'review_status' => 'pending',
        ]);

        try {
            app(ReviewJobPostingRequirement::class)->execute(
                requirement: $requirement,
                reviewer: $user,
                input: [
                    'decision' => 'approved',
                    'software_id' => $software->id,
                ],
            );

            $this->fail('An incoherent taxonomy association was accepted.');
        } catch (ValidationException) {
            $requirement->refresh();

            $this->assertSame('pending', $requirement->review_status);
            $this->assertNull($requirement->software_id);
            $this->assertNull($requirement->reviewed_at);
        }
    }

    public function test_user_cannot_review_a_requirement_owned_by_another_profile(): void
    {
        [$owner, $posting] = $this->postingOwnedByUser();
        $outsider = User::factory()->create();
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'language',
            'label' => 'English',
            'source' => 'ai',
            'review_status' => 'pending',
        ]);

        try {
            app(ReviewJobPostingRequirement::class)->execute(
                requirement: $requirement,
                reviewer: $outsider,
                input: ['decision' => 'approved'],
            );

            $this->fail('A requirement was reviewed by a user who does not own the posting.');
        } catch (AuthorizationException) {
            $requirement->refresh();

            $this->assertNotSame($owner->id, $outsider->id);
            $this->assertSame('pending', $requirement->review_status);
            $this->assertNull($requirement->reviewed_by);
        }
    }

    public function test_reviewed_requirement_cannot_be_overwritten_without_revision_history(): void
    {
        [$user, $posting] = $this->postingOwnedByUser();
        $requirement = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'experience',
            'label' => 'Three years of experience',
            'source' => 'ai',
            'review_status' => 'pending',
        ]);
        $action = app(ReviewJobPostingRequirement::class);

        $action->execute(
            requirement: $requirement,
            reviewer: $user,
            input: ['decision' => 'approved'],
        );

        try {
            $action->execute(
                requirement: $requirement,
                reviewer: $user,
                input: ['decision' => 'rejected'],
            );

            $this->fail('An existing review was overwritten.');
        } catch (ValidationException) {
            $requirement->refresh();

            $this->assertSame('approved', $requirement->review_status);
            $this->assertSame($user->id, $requirement->reviewed_by);
        }
    }

    private function postingOwnedByUser(): array
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);

        return [$user, $posting];
    }
}
