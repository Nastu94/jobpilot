<?php

namespace Tests\Feature\Foundation;

use App\Actions\Matching\CalculateDeterministicMatch;
use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\Language;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\Software;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateDeterministicMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_uses_only_approved_requirements_and_approved_profile_entries(): void
    {
        [$profile, $posting] = $this->profileAndPosting();
        $php = Skill::create(['name' => 'PHP', 'normalized_name' => 'php']);
        $laravel = Skill::create(['name' => 'Laravel', 'normalized_name' => 'laravel']);
        $profile->skills()->attach($php, ['is_approved' => true]);
        $profile->skills()->attach($laravel, ['is_approved' => false]);

        $this->requirement($posting, [
            'skill_id' => $php->id,
            'requirement_type' => 'skill',
            'label' => 'PHP',
            'review_status' => 'approved',
            'position' => 1,
        ]);
        $this->requirement($posting, [
            'skill_id' => $laravel->id,
            'requirement_type' => 'skill',
            'label' => 'Laravel',
            'review_status' => 'approved',
            'position' => 2,
        ]);
        $this->requirement($posting, [
            'requirement_type' => 'education',
            'label' => 'Bachelor degree',
            'review_status' => 'approved',
            'position' => 3,
        ]);
        $pending = $this->requirement($posting, [
            'skill_id' => $php->id,
            'requirement_type' => 'skill',
            'label' => 'Pending PHP',
            'review_status' => 'pending',
            'position' => 4,
        ]);
        $rejected = $this->requirement($posting, [
            'skill_id' => $php->id,
            'requirement_type' => 'skill',
            'label' => 'Rejected PHP',
            'review_status' => 'rejected',
            'position' => 5,
        ]);

        $analysis = app(CalculateDeterministicMatch::class)->execute($profile, $posting);
        $factors = $analysis->factors->keyBy('label');

        $this->assertSame('partial_coverage', $analysis->status);
        $this->assertSame(5000, $analysis->score_bps);
        $this->assertStringContainsString('scored 2 of 3 approved requirements', $analysis->summary);
        $this->assertSame(['PHP', 'Laravel', 'Bachelor degree'], $analysis->factors->pluck('label')->all());
        $this->assertFalse($analysis->factors->contains('key', 'requirement:'.$pending->id));
        $this->assertFalse($analysis->factors->contains('key', 'requirement:'.$rejected->id));

        $this->assertSame(5000, $factors['PHP']->weight_bps);
        $this->assertSame(10000, $factors['PHP']->score_bps);
        $this->assertSame('matched', $factors['PHP']->outcome);
        $this->assertSame(['requirement', 'profile'], $factors['PHP']->evidences->pluck('evidence_type')->all());

        $this->assertSame(5000, $factors['Laravel']->weight_bps);
        $this->assertSame(0, $factors['Laravel']->score_bps);
        $this->assertSame('gap', $factors['Laravel']->outcome);
        $this->assertSame(['requirement'], $factors['Laravel']->evidences->pluck('evidence_type')->all());

        $this->assertSame(0, $factors['Bachelor degree']->weight_bps);
        $this->assertNull($factors['Bachelor degree']->score_bps);
        $this->assertSame('not_scored', $factors['Bachelor degree']->outcome);
    }

    public function test_required_requirements_have_double_weight_and_repeated_runs_are_reproducible(): void
    {
        [$profile, $posting] = $this->profileAndPosting();
        $php = Skill::create(['name' => 'PHP', 'normalized_name' => 'php']);
        $docker = Software::create(['name' => 'Docker', 'normalized_name' => 'docker']);
        $profile->skills()->attach($php, ['is_approved' => true]);

        $this->requirement($posting, [
            'skill_id' => $php->id,
            'requirement_type' => 'skill',
            'importance' => 'required',
            'label' => 'PHP',
            'review_status' => 'approved',
            'position' => 1,
        ]);
        $this->requirement($posting, [
            'software_id' => $docker->id,
            'requirement_type' => 'software',
            'importance' => 'preferred',
            'label' => 'Docker',
            'review_status' => 'approved',
            'position' => 2,
        ]);

        $first = app(CalculateDeterministicMatch::class)->execute($profile, $posting);
        $second = app(CalculateDeterministicMatch::class)->execute($profile, $posting);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->input_hash, $second->input_hash);
        $this->assertSame(64, strlen($first->input_hash));
        $this->assertSame(6667, $first->score_bps);
        $this->assertSame(
            [
                ['PHP', 6667, 10000, 6667, 'matched'],
                ['Docker', 3333, 0, 0, 'gap'],
            ],
            $this->factorSnapshot($first),
        );
        $this->assertSame($this->factorSnapshot($first), $this->factorSnapshot($second));
        $this->assertDatabaseCount('match_analyses', 2);
    }

    public function test_years_and_cefr_levels_are_scored_with_explicit_deterministic_rules(): void
    {
        [$profile, $posting] = $this->profileAndPosting();
        $php = Skill::create(['name' => 'PHP', 'normalized_name' => 'php']);
        $english = Language::create(['name' => 'English', 'code' => 'en']);
        $profile->skills()->attach($php, [
            'years_experience' => 2,
            'is_approved' => true,
        ]);
        $profile->languages()->attach($english, [
            'proficiency_level' => 'B1',
            'is_native' => false,
        ]);

        $this->requirement($posting, [
            'skill_id' => $php->id,
            'requirement_type' => 'skill',
            'importance' => 'required',
            'label' => 'PHP',
            'min_years' => 4,
            'review_status' => 'approved',
            'position' => 1,
        ]);
        $this->requirement($posting, [
            'language_id' => $english->id,
            'requirement_type' => 'language',
            'importance' => 'preferred',
            'label' => 'English',
            'proficiency_level' => 'B2',
            'review_status' => 'approved',
            'position' => 2,
        ]);

        $analysis = app(CalculateDeterministicMatch::class)->execute($profile, $posting);
        $factors = $analysis->factors->keyBy('label');

        $this->assertSame(3333, $analysis->score_bps);
        $this->assertSame(5000, $factors['PHP']->score_bps);
        $this->assertSame(3333, $factors['PHP']->contribution_bps);
        $this->assertSame('partial', $factors['PHP']->outcome);
        $this->assertStringContainsString('2.0 years against 4.0 required', $factors['PHP']->explanation);

        $this->assertSame(0, $factors['English']->score_bps);
        $this->assertSame(0, $factors['English']->contribution_bps);
        $this->assertSame('gap', $factors['English']->outcome);
        $this->assertStringContainsString('B1', $factors['English']->explanation);
        $this->assertStringContainsString('B2', $factors['English']->explanation);
    }

    public function test_unlinked_or_unsupported_approved_requirements_are_visible_but_not_scored(): void
    {
        [$profile, $posting] = $this->profileAndPosting();
        $this->requirement($posting, [
            'requirement_type' => 'skill',
            'label' => 'PHP',
            'review_status' => 'approved',
            'position' => 1,
        ]);
        $this->requirement($posting, [
            'requirement_type' => 'experience',
            'label' => 'Three years of experience',
            'review_status' => 'approved',
            'position' => 2,
        ]);

        $analysis = app(CalculateDeterministicMatch::class)->execute($profile, $posting);

        $this->assertSame('insufficient_data', $analysis->status);
        $this->assertNull($analysis->score_bps);
        $this->assertSame([0, 0], $analysis->factors->pluck('weight_bps')->all());
        $this->assertSame([null, null], $analysis->factors->pluck('score_bps')->all());
        $this->assertSame(['unresolved', 'not_scored'], $analysis->factors->pluck('outcome')->all());
        $this->assertSame(64, strlen($analysis->input_hash));
    }

    public function test_profile_cannot_calculate_matching_for_another_profiles_posting(): void
    {
        [$profile] = $this->profileAndPosting();
        [, $otherPosting] = $this->profileAndPosting();

        $this->expectException(AuthorizationException::class);

        try {
            app(CalculateDeterministicMatch::class)->execute($profile, $otherPosting);
        } finally {
            $this->assertDatabaseCount('match_analyses', 0);
        }
    }

    private function profileAndPosting(): array
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);

        return [$profile, $posting];
    }

    private function requirement(JobPosting $posting, array $attributes): JobPostingRequirement
    {
        return JobPostingRequirement::create(array_merge([
            'job_posting_id' => $posting->id,
            'importance' => 'required',
            'source' => 'manual',
            'review_status' => 'approved',
            'evidence' => 'Requirement evidence.',
        ], $attributes));
    }

    private function factorSnapshot($analysis): array
    {
        return $analysis->factors
            ->map(fn ($factor): array => [
                $factor->label,
                $factor->weight_bps,
                $factor->score_bps,
                $factor->contribution_bps,
                $factor->outcome,
            ])
            ->all();
    }
}
