<?php

namespace Tests\Feature\Foundation;

use App\Models\Certification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_ordered_certifications(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $older = Certification::create([
            'profile_id' => $profile->id,
            'name' => 'Older certification',
            'issuing_organization' => 'Issuer A',
            'issue_date' => '2021-01-15',
            'expiry_date' => '2024-01-15',
        ]);

        $newer = Certification::create([
            'profile_id' => $profile->id,
            'name' => 'Newer certification',
            'issuing_organization' => 'Issuer B',
            'issue_date' => '2024-06-01',
            'does_not_expire' => true,
        ]);

        $certifications = $profile->fresh()->certifications;

        $this->assertSame([$newer->id, $older->id], $certifications->pluck('id')->all());
        $this->assertTrue($newer->fresh()->profile->is($profile));
        $this->assertTrue($newer->fresh()->does_not_expire);
        $this->assertSame('2024-06-01', $newer->fresh()->issue_date->toDateString());
        $this->assertSame('2024-01-15', $older->fresh()->expiry_date->toDateString());
    }

    public function test_deleting_profile_removes_certifications(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $certification = Certification::create([
            'profile_id' => $profile->id,
            'name' => 'Example certification',
            'issuing_organization' => 'Example issuer',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('certifications', ['id' => $certification->id]);
    }
}
