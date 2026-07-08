<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationTimeline;
use App\Actions\Applications\RecordJobApplicationInteraction;
use App\Models\JobApplication;
use App\Models\JobApplicationDocumentVersionHistory;
use App\Models\JobApplicationInteraction;
use App\Models\JobApplicationTrackingHistory;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\JobApplicationTimelineBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildJobApplicationTimelineInteractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorded_interaction_is_exposed_as_a_normalized_timeline_event(): void
    {
        [$owner, $application] = $this->scenario();
        $occurredAt = now()->subHour()->startOfSecond();
        $interaction = app(RecordJobApplicationInteraction::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'timeline-interaction-001',
                'interaction_type' => 'phone_call',
                'direction' => 'outbound',
                'subject' => 'Availability call',
                'contact_name' => 'Mario Rossi',
                'contact_email' => 'mario@example.com',
                'occurred_at' => $occurredAt->toDateTimeString(),
                'notes' => 'Confirmed interview availability.',
            ],
        );

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
            [
                'event_types' => [
                    JobApplicationTimelineBuilder::TYPE_INTERACTION_RECORDED,
                ],
                'direction' => 'asc',
            ],
        );

        $this->assertSame(1, $timeline['summary']['available_total']);
        $this->assertSame(1, $timeline['summary']['matching_total']);
        $this->assertSame(1, $timeline['summary']['returned_total']);
        $this->assertSame(1, $timeline['summary']['available_by_type']['interaction_recorded']);
        $this->assertSame(1, $timeline['summary']['returned_by_type']['interaction_recorded']);

        $event = $timeline['events'][0];
        $this->assertSame('interaction_recorded:'.$interaction->id, $event['event_key']);
        $this->assertSame('interaction_recorded', $event['event_type']);
        $this->assertSame('application_interaction_recorded', $event['summary_code']);
        $this->assertSame($occurredAt->toISOString(), $event['occurred_at']);
        $this->assertSame($owner->id, $event['actor']['id']);
        $this->assertSame('timeline-interaction-001', $event['details']['client_reference']);
        $this->assertSame('phone_call', $event['details']['interaction_type']);
        $this->assertSame('outbound', $event['details']['direction']);
        $this->assertSame('Availability call', $event['details']['subject']);
        $this->assertSame('Mario Rossi', $event['details']['contact_name']);
        $this->assertSame('mario@example.com', $event['details']['contact_email']);
        $this->assertSame('Confirmed interview availability.', $event['details']['notes']);
    }

    public function test_same_timestamp_places_interaction_between_tracking_and_document_selection(): void
    {
        [$owner, $application] = $this->scenario();
        $timestamp = now()->subHour()->startOfSecond();
        JobApplicationDocumentVersionHistory::create([
            'job_application_id' => $application->id,
            'changed_by' => $owner->id,
            'generated_document_id' => 7,
            'previous_generated_document_version_id' => 11,
            'generated_document_version_id' => 12,
            'previous_resume_version_id' => 21,
            'resume_version_id' => 22,
            'previous_version_number' => 1,
            'version_number' => 2,
            'previous_checksum_sha256' => str_repeat('a', 64),
            'checksum_sha256' => str_repeat('b', 64),
            'previous_reviewed_content_sha256' => str_repeat('a', 64),
            'reviewed_content_sha256' => str_repeat('b', 64),
            'changed_at' => $timestamp,
        ]);
        JobApplicationInteraction::create([
            'job_application_id' => $application->id,
            'recorded_by' => $owner->id,
            'interaction_type' => 'email',
            'direction' => 'inbound',
            'subject' => 'Recruiter reply',
            'occurred_at' => $timestamp,
        ]);
        JobApplicationTrackingHistory::create([
            'job_application_id' => $application->id,
            'changed_by' => $owner->id,
            'change_source' => 'manual_update',
            'changed_at' => $timestamp,
        ]);

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
        );

        $this->assertSame(
            [
                JobApplicationTimelineBuilder::TYPE_TRACKING_UPDATED,
                JobApplicationTimelineBuilder::TYPE_INTERACTION_RECORDED,
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_VERSION_SELECTED,
            ],
            array_column($timeline['events'], 'event_type'),
        );
    }

    public function test_deleted_interaction_actor_is_preserved_as_null_in_timeline(): void
    {
        [$owner, $application] = $this->scenario();
        $secondaryActor = User::factory()->create();
        JobApplicationInteraction::create([
            'job_application_id' => $application->id,
            'recorded_by' => $secondaryActor->id,
            'interaction_type' => 'other',
            'direction' => 'internal',
            'subject' => 'Imported interaction',
            'occurred_at' => now()->subHour()->startOfSecond(),
        ]);
        $secondaryActor->delete();

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
            [
                'event_types' => [
                    JobApplicationTimelineBuilder::TYPE_INTERACTION_RECORDED,
                ],
            ],
        );

        $this->assertNull($timeline['events'][0]['actor']);
        $this->assertSame('Imported interaction', $timeline['events'][0]['details']['subject']);
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
}
