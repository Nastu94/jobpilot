<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\ProfileLocationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileLocationPreferenceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_ordered_location_preferences_and_relocation_choice(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'willing_to_relocate' => true,
        ]);

        $second = ProfileLocationPreference::create([
            'profile_id' => $profile->id,
            'location' => 'Milano',
            'country_code' => 'IT',
            'position' => 2,
        ]);

        $first = ProfileLocationPreference::create([
            'profile_id' => $profile->id,
            'location' => 'Roma',
            'country_code' => 'IT',
            'position' => 1,
        ]);

        $preferences = $profile->fresh()->locationPreferences;

        $this->assertSame([$first->id, $second->id], $preferences->pluck('id')->all());
        $this->assertTrue($first->fresh()->profile->is($profile));
        $this->assertTrue($profile->fresh()->willing_to_relocate);
        $this->assertSame(1, $first->fresh()->position);
    }

    public function test_deleting_profile_removes_location_preferences(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $preference = ProfileLocationPreference::create([
            'profile_id' => $profile->id,
            'location' => 'Roma',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('profile_location_preferences', ['id' => $preference->id]);
    }
}
