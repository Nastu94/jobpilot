<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_profile_have_a_one_to_one_relationship(): void
    {
        $user = User::factory()->create();

        $profile = Profile::create([
            'user_id' => $user->id,
            'availability' => 'available',
            'desired_ral_min' => 28000,
            'desired_ral_max' => 34000,
            'remote_preference' => 'hybrid',
        ]);

        $this->assertTrue($user->profile->is($profile));
        $this->assertTrue($profile->user->is($user));
        $this->assertSame(28000, $profile->desired_ral_min);
        $this->assertSame(34000, $profile->desired_ral_max);
    }

    public function test_user_cannot_have_more_than_one_profile(): void
    {
        $user = User::factory()->create();

        Profile::create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);

        Profile::create(['user_id' => $user->id]);
    }
}
