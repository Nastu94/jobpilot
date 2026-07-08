<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\UpdateJobApplicationTrackingDetails;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdateJobApplicationTrackingDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_tracking_details_with_before_and_after_audit(): void
    {
        [$owner, $application] = $this->scenario();
        $changedAt = now()->subMinute()->startOfSecond();
        $nextActionAt = now()->addDays(3)->startOfSecond();

        $updated = app(UpdateJobApplicationTrackingDetails::class)->execute(
            $application,
            $owner,
            [
                'changed_at' => $changedAt->toDateTimeString(),
                'application_channel' => '  Company   website  ',
                'external_reference' => '  APP-204  ',
                'next_action_at' => $nextActionAt->toDateTimeString(),
                'notes' => "  First line.\nSecond line.  ",
            ],
        );

        $this->assertSame('Company website', $updated->application_channel);
        $this->assertSame('APP-204', $updated->external_reference);
        $this->assertSame($nextActionAt->toDateTimeString(), $updated->next_action_at->toDateTimeString());
        $this->assertSame("First line.\nSecond line.", $updated->notes);
        $this->assertCount(1, $updated->trackingHistory);

        $history = $updated->trackingHistory->first();
        $this->assertSame('manual_update', $history->change_source);
        $this->assertSame($owner->id, $history->changed_by);
        $this->assertTrue($history->changedBy->is($owner));
        $this->assertSame('Email', $history->previous_application_channel);
        $this->assertSame('Company website', $history->application_channel);
        $this->assertSame('REF-1', $history->previous_external_reference);
        $this->assertSame('APP-204', $history->external_reference);
        $this->assertSame('Initial note.', $history->previous_notes);
        $this->assertSame("First line.\nSecond line.", $history->notes);
        $this->assertSame($changedAt->toDateTimeString(), $history->changed_at->toDateTimeString());
    }

    public function test_partial_update_preserves_omitted_fields(): void
    {
        [$owner, $application] = $this->scenario();
        $previousNextAction = $application->next_action_at->toDateTimeString();

        $updated = app(UpdateJobApplicationTrackingDetails::class)->execute(
            $application,
            $owner,
            ['notes' => 'Updated note only.'],
        );

        $this->assertSame('Email', $updated->application_channel);
        $this->assertSame('REF-1', $updated->external_reference);
        $this->assertSame($previousNextAction, $updated->next_action_at->toDateTimeString());
        $this->assertSame('Updated note only.', $updated->notes);
        $this->assertCount(1, $updated->trackingHistory);
        $this->assertSame('Initial note.', $updated->trackingHistory->first()->previous_notes);
        $this->assertSame('Updated note only.', $updated->trackingHistory->first()->notes);
    }

    public function test_repeating_equivalent_values_is_idempotent(): void
    {
        [$owner, $application] = $this->scenario();

        $updated = app(UpdateJobApplicationTrackingDetails::class)->execute(
            $application,
            $owner,
            [
                'application_channel' => '  Email  ',
                'external_reference' => ' REF-1 ',
                'notes' => ' Initial note. ',
            ],
        );

        $this->assertSame($application->id, $updated->id);
        $this->assertDatabaseCount('job_application_tracking_histories', 0);
    }

    public function test_owner_can_explicitly_clear_tracking_details(): void
    {
        [$owner, $application] = $this->scenario();

        $updated = app(UpdateJobApplicationTrackingDetails::class)->execute(
            $application,
            $owner,
            [
                'application_channel' => null,
                'external_reference' => '   ',
                'next_action_at' => null,
                'notes' => '',
            ],
        );

        $this->assertNull($updated->application_channel);
        $this->assertNull($updated->external_reference);
        $this->assertNull($updated->next_action_at);
        $this->assertNull($updated->notes);
        $this->assertCount(1, $updated->trackingHistory);
        $this->assertNotNull($updated->trackingHistory->first()->previous_next_action_at);
        $this->assertNull($updated->trackingHistory->first()->next_action_at);
    }

    public function test_terminal_application_cannot_receive_a_next_action(): void
    {
        [$owner, $application] = $this->scenario(['status' => 'hired']);

        try {
            app(UpdateJobApplicationTrackingDetails::class)->execute(
                $application,
                $owner,
                ['next_action_at' => now()->addDay()->toDateTimeString()],
            );

            $this->fail('A terminal application received a next action.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('next_action_at', $exception->errors());
            $this->assertDatabaseCount('job_application_tracking_histories', 0);
        }
    }

    public function test_next_action_must_be_in_the_future(): void
    {
        [$owner, $application] = $this->scenario();

        try {
            app(UpdateJobApplicationTrackingDetails::class)->execute(
                $application,
                $owner,
                ['next_action_at' => now()->subMinute()->toDateTimeString()],
            );

            $this->fail('A past next action was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('next_action_at', $exception->errors());
            $this->assertDatabaseCount('job_application_tracking_histories', 0);
        }
    }

    public function test_tracking_update_cannot_precede_latest_tracking_history(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(UpdateJobApplicationTrackingDetails::class);
        $action->execute(
            $application,
            $owner,
            [
                'changed_at' => now()->subHour()->toDateTimeString(),
                'notes' => 'First tracked update.',
            ],
        );

        try {
            $action->execute(
                $application,
                $owner,
                [
                    'changed_at' => now()->subHours(2)->toDateTimeString(),
                    'notes' => 'Backdated update.',
                ],
            );

            $this->fail('A backdated tracking update was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame('First tracked update.', $application->fresh()->notes);
            $this->assertDatabaseCount('job_application_tracking_histories', 1);
        }
    }

    public function test_at_least_one_tracking_field_is_required(): void
    {
        [$owner, $application] = $this->scenario();

        try {
            app(UpdateJobApplicationTrackingDetails::class)->execute(
                $application,
                $owner,
                ['changed_at' => now()->subMinute()->toDateTimeString()],
            );

            $this->fail('A tracking update without fields was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tracking', $exception->errors());
            $this->assertDatabaseCount('job_application_tracking_histories', 0);
        }
    }

    public function test_user_cannot_update_another_users_application(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        try {
            app(UpdateJobApplicationTrackingDetails::class)->execute(
                $application,
                $outsider,
                ['notes' => 'Unauthorized update.'],
            );

            $this->fail('An outsider updated application tracking details.');
        } catch (AuthorizationException) {
            $this->assertSame('Initial note.', $application->fresh()->notes);
            $this->assertDatabaseCount('job_application_tracking_histories', 0);
        }
    }

    public function test_deleting_application_removes_tracking_history(): void
    {
        [$owner, $application] = $this->scenario();
        app(UpdateJobApplicationTrackingDetails::class)->execute(
            $application,
            $owner,
            ['notes' => 'Tracked before deletion.'],
        );

        $this->assertDatabaseCount('job_application_tracking_histories', 1);

        $application->delete();

        $this->assertDatabaseCount('job_application_tracking_histories', 0);
    }

    private function scenario(array $overrides = []): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $application = JobApplication::create(array_merge([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'screening',
            'application_channel' => 'Email',
            'external_reference' => 'REF-1',
            'next_action_at' => now()->addDays(2)->startOfSecond(),
            'notes' => 'Initial note.',
        ], $overrides));

        return [$owner, $application];
    }
}
