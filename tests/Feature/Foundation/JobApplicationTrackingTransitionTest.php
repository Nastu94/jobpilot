<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobApplicationTrackingTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_transition_records_tracking_metadata_changes(): void
    {
        [$owner, $application] = $this->scenario([
            'status' => 'applied',
            'application_channel' => 'Email',
            'external_reference' => 'OLD-REF',
        ]);
        $nextActionAt = now()->addDay()->startOfSecond();
        $changedAt = now()->subMinute()->startOfSecond();

        $updated = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'screening',
                'changed_at' => $changedAt->toDateTimeString(),
                'application_channel' => '  Company portal  ',
                'external_reference' => '  NEW-REF  ',
                'next_action_at' => $nextActionAt->toDateTimeString(),
            ],
        );

        $this->assertSame('screening', $updated->status);
        $this->assertCount(1, $updated->trackingHistory);

        $history = $updated->trackingHistory->first();
        $this->assertSame('status_transition', $history->change_source);
        $this->assertSame($owner->id, $history->changed_by);
        $this->assertSame('Email', $history->previous_application_channel);
        $this->assertSame('Company portal', $history->application_channel);
        $this->assertSame('OLD-REF', $history->previous_external_reference);
        $this->assertSame('NEW-REF', $history->external_reference);
        $this->assertNull($history->previous_next_action_at);
        $this->assertSame(
            $nextActionAt->toDateTimeString(),
            $history->next_action_at->toDateTimeString(),
        );
        $this->assertSame($changedAt->toDateTimeString(), $history->changed_at->toDateTimeString());
    }

    public function test_status_only_transition_does_not_create_tracking_history(): void
    {
        [$owner, $application] = $this->scenario(['status' => 'applied']);

        $updated = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'screening',
                'changed_at' => now()->subMinute()->toDateTimeString(),
            ],
        );

        $this->assertSame('screening', $updated->status);
        $this->assertDatabaseCount('job_application_tracking_histories', 0);
    }

    public function test_terminal_transition_audits_automatic_next_action_clear(): void
    {
        $nextActionAt = now()->addDay()->startOfSecond();
        [$owner, $application] = $this->scenario([
            'status' => 'offer',
            'next_action_at' => $nextActionAt,
        ]);

        $updated = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'hired',
                'changed_at' => now()->subMinute()->toDateTimeString(),
            ],
        );

        $this->assertNull($updated->next_action_at);
        $this->assertCount(1, $updated->trackingHistory);
        $this->assertSame(
            $nextActionAt->toDateTimeString(),
            $updated->trackingHistory->first()->previous_next_action_at->toDateTimeString(),
        );
        $this->assertNull($updated->trackingHistory->first()->next_action_at);
    }

    private function scenario(array $overrides = []): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $application = JobApplication::create(array_merge([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'applied',
        ], $overrides));

        return [$owner, $application];
    }
}
