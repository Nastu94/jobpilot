<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\UpdateJobApplicationTrackingDetails;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JobApplicationTrackingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_tracking_change_cannot_be_recorded_in_the_future(): void
    {
        [$owner, $application] = $this->scenario();

        try {
            app(UpdateJobApplicationTrackingDetails::class)->execute(
                $application,
                $owner,
                [
                    'changed_at' => now()->addHour()->toDateTimeString(),
                    'notes' => 'Future update.',
                ],
            );

            $this->fail('A future tracking update was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame('Initial note.', $application->fresh()->notes);
            $this->assertDatabaseCount('job_application_tracking_histories', 0);
        }
    }

    public function test_exact_replay_remains_idempotent_even_with_an_older_timestamp(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(UpdateJobApplicationTrackingDetails::class);
        $updated = $action->execute(
            $application,
            $owner,
            [
                'changed_at' => now()->subHour()->toDateTimeString(),
                'notes' => 'Final note.',
            ],
        );

        $replayed = $action->execute(
            $updated,
            $owner,
            [
                'changed_at' => now()->subHours(2)->toDateTimeString(),
                'notes' => 'Final note.',
            ],
        );

        $this->assertSame('Final note.', $replayed->notes);
        $this->assertDatabaseCount('job_application_tracking_histories', 1);
    }

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'screening',
            'notes' => 'Initial note.',
        ]);

        return [$owner, $application];
    }
}
