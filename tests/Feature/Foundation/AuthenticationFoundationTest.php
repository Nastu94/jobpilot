<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_candidate_profile_and_sends_verification_email(): void
    {
        Notification::fake();
        $this->seed(RoleSeeder::class);

        $response = $this->postJson('/register', [
            'name' => 'Candidate User',
            'email' => 'candidate@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertCreated();

        $user = User::query()->where('email', 'candidate@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->profile);
        $this->assertTrue($user->hasRole('candidate'));
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_rejects_duplicate_email_without_creating_extra_profile(): void
    {
        Notification::fake();
        $this->seed(RoleSeeder::class);

        $payload = [
            'name' => 'Candidate User',
            'email' => 'candidate@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ];

        $this->postJson('/register', $payload)->assertCreated();
        $this->postJson('/logout')->assertNoContent();

        $response = $this->postJson('/register', $payload);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('email', $response->json('errors'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('profiles', 1);
    }

    public function test_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create([
            'email' => 'candidate@example.com',
            'password' => Hash::make('SecurePassword123!'),
        ]);

        $this->postJson('/login', [
            'email' => 'candidate@example.com',
            'password' => 'SecurePassword123!',
        ])
            ->assertOk()
            ->assertJson(['two_factor' => false]);

        $this->assertAuthenticatedAs($user);

        $this->postJson('/logout')->assertNoContent();

        $this->assertGuest();
    }
}
