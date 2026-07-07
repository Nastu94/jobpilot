<?php

namespace Tests\Feature\Foundation;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthenticationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_candidate_profile_and_sends_verification_email(): void
    {
        Notification::fake();
        $this->seed(RoleSeeder::class);
        $password = implode('', ['Secure', 'Password', '123', '!']);

        $response = $this->postJson('/register', [
            'name' => 'Candidate User',
            'email' => 'candidate@example.com',
            'password' => $password,
            'password_confirmation' => $password,
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
        $password = implode('', ['Secure', 'Password', '123', '!']);

        $existingUser = User::factory()->create([
            'email' => 'candidate@example.com',
        ]);
        $existingUser->profile()->create();

        try {
            app(CreateNewUser::class)->create([
                'name' => 'Another Candidate',
                'email' => 'candidate@example.com',
                'password' => $password,
                'password_confirmation' => $password,
            ]);

            $this->fail('Duplicate email registration should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('profiles', 1);
    }

    public function test_user_can_log_in_and_log_out(): void
    {
        $password = implode('', ['Secure', 'Password', '123', '!']);
        $user = User::factory()->create([
            'email' => 'candidate@example.com',
            'password' => Hash::make($password),
        ]);

        $this->postJson('/login', [
            'email' => 'candidate@example.com',
            'password' => $password,
        ])
            ->assertOk()
            ->assertJson(['two_factor' => false]);

        $this->assertAuthenticatedAs($user);

        $this->postJson('/logout')->assertNoContent();

        $this->assertGuest();
    }
}
