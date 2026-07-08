<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\RecordJobApplicationInteraction;
use App\Models\JobApplication;
use App\Models\JobApplicationInteraction;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordJobApplicationInteractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_record_a_normalized_interaction(): void
    {
        [$owner, $application] = $this->scenario();
        $occurredAt = now()->subHour()->startOfSecond();

        $interaction = app(RecordJobApplicationInteraction::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => '  ui:interaction:001  ',
                'interaction_type' => 'interview',
                'direction' => 'meeting',
                'subject' => '  Technical   interview  ',
                'contact_name' => '  Mario   Rossi  ',
                'contact_email' => '  Recruiter@Example.COM ',
                'occurred_at' => $occurredAt->toDateTimeString(),
                'notes' => "  Discussed Laravel and SQL.\nPositive feedback.  ",
            ],
        );

        $this->assertSame($application->id, $interaction->job_application_id);
        $this->assertSame($owner->id, $interaction->recorded_by);
        $this->assertTrue($interaction->recordedBy->is($owner));
        $this->assertSame('ui:interaction:001', $interaction->client_reference);
        $this->assertSame('interview', $interaction->interaction_type);
        $this->assertSame('meeting', $interaction->direction);
        $this->assertSame('Technical interview', $interaction->subject);
        $this->assertSame('Mario Rossi', $interaction->contact_name);
        $this->assertSame('recruiter@example.com', $interaction->contact_email);
        $this->assertSame($occurredAt->toDateTimeString(), $interaction->occurred_at->toDateTimeString());
        $this->assertSame("Discussed Laravel and SQL.\nPositive feedback.", $interaction->notes);
        $this->assertCount(1, $application->fresh()->interactions);
    }

    public function test_same_client_reference_and_payload_are_idempotent(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(RecordJobApplicationInteraction::class);
        $payload = $this->payload([
            'client_reference' => 'retry-safe-001',
        ]);

        $first = $action->execute($application, $owner, $payload);
        $second = $action->execute($application, $owner, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('job_application_interactions', 1);
    }

    public function test_same_client_reference_with_different_payload_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(RecordJobApplicationInteraction::class);
        $action->execute($application, $owner, $this->payload([
            'client_reference' => 'retry-safe-002',
        ]));

        try {
            $action->execute($application, $owner, $this->payload([
                'client_reference' => 'retry-safe-002',
                'notes' => 'Different notes.',
            ]));

            $this->fail('A reused client reference accepted another payload.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_reference', $exception->errors());
            $this->assertDatabaseCount('job_application_interactions', 1);
        }
    }

    public function test_interactions_without_client_reference_are_distinct_events(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(RecordJobApplicationInteraction::class);
        $payload = $this->payload();

        $first = $action->execute($application, $owner, $payload);
        $second = $action->execute($application, $owner, $payload);

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('job_application_interactions', 2);
    }

    public function test_future_interaction_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();

        try {
            app(RecordJobApplicationInteraction::class)->execute(
                $application,
                $owner,
                $this->payload([
                    'occurred_at' => now()->addHour()->toDateTimeString(),
                ]),
            );

            $this->fail('A future interaction was recorded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('occurred_at', $exception->errors());
            $this->assertDatabaseCount('job_application_interactions', 0);
        }
    }

    public function test_interaction_requires_supported_type_direction_and_context(): void
    {
        [$owner, $application] = $this->scenario();

        foreach ([
            $this->payload(['interaction_type' => 'fax']),
            $this->payload(['direction' => 'sideways']),
            $this->payload(['subject' => '   ', 'notes' => "\n  "]),
            $this->payload(['unknown' => true]),
        ] as $payload) {
            try {
                app(RecordJobApplicationInteraction::class)->execute(
                    $application,
                    $owner,
                    $payload,
                );

                $this->fail('An invalid interaction was recorded.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('job_application_interactions', 0);
    }

    public function test_terminal_application_can_still_receive_a_historical_interaction(): void
    {
        [$owner, $application] = $this->scenario();
        $application->forceFill(['status' => 'rejected'])->save();

        $interaction = app(RecordJobApplicationInteraction::class)->execute(
            $application,
            $owner,
            $this->payload([
                'interaction_type' => 'email',
                'direction' => 'inbound',
                'subject' => 'Rejection received',
            ]),
        );

        $this->assertSame('email', $interaction->interaction_type);
        $this->assertDatabaseCount('job_application_interactions', 1);
    }

    public function test_user_cannot_record_an_interaction_for_another_users_application(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(RecordJobApplicationInteraction::class)->execute(
            $application,
            $outsider,
            $this->payload(),
        );
    }

    public function test_actor_deletion_is_preserved_as_null_and_application_deletion_cascades(): void
    {
        [$owner, $application] = $this->scenario();
        $interaction = app(RecordJobApplicationInteraction::class)->execute(
            $application,
            $owner,
            $this->payload(),
        );

        $owner->delete();
        $preserved = JobApplicationInteraction::query()->findOrFail($interaction->id);

        $this->assertNull($preserved->recorded_by);
        $this->assertNull($preserved->recordedBy);

        $application->delete();

        $this->assertDatabaseCount('job_application_interactions', 0);
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
        ]);

        return [$owner, $application];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'interaction_type' => 'recruiter_message',
            'direction' => 'inbound',
            'subject' => 'Recruiter follow-up',
            'contact_name' => 'Mario Rossi',
            'contact_email' => 'mario@example.com',
            'occurred_at' => now()->subHour()->startOfSecond()->toDateTimeString(),
            'notes' => 'Requested availability for a call.',
        ], $overrides);
    }
}
