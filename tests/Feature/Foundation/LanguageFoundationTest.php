<?php

namespace Tests\Feature\Foundation;

use App\Models\Language;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguageFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_language_metadata(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $language = Language::create([
            'name' => 'Italian',
            'code' => 'it',
        ]);

        $profile->languages()->attach($language, [
            'proficiency_level' => 'native',
            'is_native' => true,
            'notes' => 'Primary language',
        ]);

        $this->assertTrue($profile->fresh()->languages->contains($language));
        $this->assertTrue($language->fresh()->profiles->contains($profile));
        $this->assertDatabaseHas('profile_language', [
            'profile_id' => $profile->id,
            'language_id' => $language->id,
            'proficiency_level' => 'native',
            'is_native' => true,
        ]);
    }

    public function test_language_names_are_unique(): void
    {
        Language::create([
            'name' => 'English',
            'code' => 'en',
        ]);

        $this->expectException(QueryException::class);

        Language::create([
            'name' => 'English',
            'code' => 'eng',
        ]);
    }

    public function test_same_language_cannot_be_attached_twice_to_profile(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $language = Language::create([
            'name' => 'French',
            'code' => 'fr',
        ]);

        $profile->languages()->attach($language);

        $this->expectException(QueryException::class);

        $profile->languages()->attach($language);
    }

    public function test_deleting_language_removes_profile_links(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id]);
        $language = Language::create([
            'name' => 'German',
            'code' => 'de',
        ]);

        $profile->languages()->attach($language);
        $language->delete();

        $this->assertDatabaseMissing('profile_language', [
            'profile_id' => $profile->id,
            'language_id' => $language->id,
        ]);
    }
}
