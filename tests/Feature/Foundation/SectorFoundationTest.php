<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectorFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_have_sectors(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $sector = Sector::create(['name' => 'Sector A']);

        $profile->sectors()->attach($sector);

        $this->assertTrue($profile->fresh()->sectors->contains($sector));
        $this->assertTrue($sector->fresh()->profiles->contains($profile));
    }

    public function test_profile_sector_pair_is_unique(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $sector = Sector::create(['name' => 'Sector B']);

        $profile->sectors()->attach($sector);

        $this->expectException(QueryException::class);
        $profile->sectors()->attach($sector);
    }
}
