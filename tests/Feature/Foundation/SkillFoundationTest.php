<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_skill_metadata_and_skill_aliases(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $skill = Skill::create([
            'name' => 'Microsoft Excel',
            'normalized_name' => 'microsoft excel',
            'category' => 'technical',
        ]);

        $alias = $skill->aliases()->create([
            'alias' => 'MS Excel',
            'normalized_alias' => 'ms excel',
        ]);

        $profile->skills()->attach($skill, [
            'proficiency_level' => 'advanced',
            'years_experience' => 4.5,
            'source' => 'manual',
            'is_approved' => true,
            'notes' => 'Used for reporting and analysis',
        ]);

        $this->assertTrue($profile->fresh()->skills->contains($skill));
        $this->assertTrue($skill->fresh()->profiles->contains($profile));
        $this->assertTrue($skill->fresh()->aliases->contains($alias));
        $this->assertTrue($alias->fresh()->skill->is($skill));

        $this->assertDatabaseHas('profile_skill', [
            'profile_id' => $profile->id,
            'skill_id' => $skill->id,
            'proficiency_level' => 'advanced',
            'source' => 'manual',
            'is_approved' => true,
        ]);
    }

    public function test_normalized_skill_names_and_aliases_are_unique(): void
    {
        Skill::create([
            'name' => 'Microsoft Excel',
            'normalized_name' => 'microsoft excel',
        ]);

        $this->expectException(QueryException::class);

        Skill::create([
            'name' => 'Excel',
            'normalized_name' => 'microsoft excel',
        ]);
    }

    public function test_same_skill_cannot_be_attached_twice_to_profile(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $skill = Skill::create([
            'name' => 'Inventory management',
            'normalized_name' => 'inventory management',
        ]);

        $profile->skills()->attach($skill);

        $this->expectException(QueryException::class);

        $profile->skills()->attach($skill);
    }

    public function test_deleting_skill_removes_aliases_and_profile_links(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $skill = Skill::create([
            'name' => 'Data analysis',
            'normalized_name' => 'data analysis',
        ]);
        $alias = $skill->aliases()->create([
            'alias' => 'Data analytics',
            'normalized_alias' => 'data analytics',
        ]);

        $profile->skills()->attach($skill);
        $skill->delete();

        $this->assertDatabaseMissing('skill_aliases', ['id' => $alias->id]);
        $this->assertDatabaseMissing('profile_skill', [
            'profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);
    }
}
