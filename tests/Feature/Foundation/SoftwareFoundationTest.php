<?php

namespace Tests\Feature\Foundation;

use App\Models\Profile;
use App\Models\Software;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftwareFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_software_metadata_and_aliases(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);

        $software = Software::create([
            'name' => 'Microsoft Dynamics 365',
            'normalized_name' => 'microsoft dynamics 365',
            'vendor' => 'Microsoft',
            'category' => 'erp',
        ]);

        $alias = $software->aliases()->create([
            'alias' => 'Dynamics 365',
            'normalized_alias' => 'dynamics 365',
        ]);

        $profile->software()->attach($software, [
            'proficiency_level' => 'intermediate',
            'years_experience' => 2.5,
            'source' => 'manual',
            'is_approved' => true,
            'notes' => 'Used for order management',
        ]);

        $this->assertTrue($profile->fresh()->software->contains($software));
        $this->assertTrue($software->fresh()->profiles->contains($profile));
        $this->assertTrue($software->fresh()->aliases->contains($alias));
        $this->assertTrue($alias->fresh()->software->is($software));

        $this->assertDatabaseHas('profile_software', [
            'profile_id' => $profile->id,
            'software_id' => $software->id,
            'proficiency_level' => 'intermediate',
            'source' => 'manual',
            'is_approved' => true,
        ]);
    }

    public function test_normalized_software_names_are_unique(): void
    {
        Software::create([
            'name' => 'SAP',
            'normalized_name' => 'sap',
        ]);

        $this->expectException(QueryException::class);

        Software::create([
            'name' => 'SAP ERP',
            'normalized_name' => 'sap',
        ]);
    }

    public function test_same_software_cannot_be_attached_twice_to_profile(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $software = Software::create([
            'name' => 'Power BI',
            'normalized_name' => 'power bi',
        ]);

        $profile->software()->attach($software);

        $this->expectException(QueryException::class);

        $profile->software()->attach($software);
    }

    public function test_deleting_software_removes_aliases_and_profile_links(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $software = Software::create([
            'name' => 'Oracle NetSuite',
            'normalized_name' => 'oracle netsuite',
        ]);
        $alias = $software->aliases()->create([
            'alias' => 'NetSuite',
            'normalized_alias' => 'netsuite',
        ]);

        $profile->software()->attach($software);
        $software->delete();

        $this->assertDatabaseMissing('software_aliases', ['id' => $alias->id]);
        $this->assertDatabaseMissing('profile_software', [
            'profile_id' => $profile->id,
            'software_id' => $software->id,
        ]);
    }
}
