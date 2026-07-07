<?php

namespace Tests\Feature\Foundation;

use App\Models\Education;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EducationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_ordered_academic_history(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $older = Education::create([
            'profile_id' => $profile->id,
            'institution' => 'First Institute',
            'qualification' => 'First qualification',
            'start_date' => '2015-09-01',
            'end_date' => '2020-06-30',
        ]);

        $newer = Education::create([
            'profile_id' => $profile->id,
            'institution' => 'Second Institute',
            'qualification' => 'Second qualification',
            'start_date' => '2021-09-01',
            'is_current' => true,
        ]);

        $history = $profile->fresh()->academicHistory;

        $this->assertSame([$newer->id, $older->id], $history->pluck('id')->all());
        $this->assertTrue($newer->fresh()->profile->is($profile));
        $this->assertTrue($newer->fresh()->is_current);
        $this->assertSame('2021-09-01', $newer->fresh()->start_date->toDateString());
    }

    public function test_deleting_profile_removes_academic_history(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $education = Education::create([
            'profile_id' => $profile->id,
            'institution' => 'Example Institute',
            'qualification' => 'Example qualification',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('educations', ['id' => $education->id]);
    }
}
