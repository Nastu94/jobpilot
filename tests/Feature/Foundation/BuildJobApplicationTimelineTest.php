<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationTimeline;
use App\Models\JobApplication;
use App\Models\JobApplicationDocumentAccessHistory;
use App\Models\JobApplicationDocumentVersionHistory;
use App\Models\JobApplicationStatusHistory;
use App\Models\JobApplicationTrackingHistory;
use App\Models\Profile;
use App\Models\User;
use App\Services\Applications\JobApplicationTimelineBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildJobApplicationTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_receives_normalized_events_from_every_history_source(): void
    {
        [$owner, $application] = $this->scenario();
        $status = $this->statusHistory($application, $owner, '2026-07-08 09:00:00');
        $tracking = $this->trackingHistory($application, $owner, '2026-07-08 10:00:00');
        $selection = $this->selectionHistory($application, $owner, '2026-07-08 11:00:00');
        $access = $this->accessHistory($application, $owner, '2026-07-08 12:00:00');

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
            ['direction' => 'asc'],
        );

        $this->assertSame($application->id, $timeline['application']['id']);
        $this->assertSame('screening', $timeline['application']['status']);
        $this->assertSame(4, $timeline['summary']['available_total']);
        $this->assertSame(4, $timeline['summary']['matching_total']);
        $this->assertSame(4, $timeline['summary']['returned_total']);
        $this->assertSame(
            [
                JobApplicationTimelineBuilder::TYPE_STATUS_CHANGED,
                JobApplicationTimelineBuilder::TYPE_TRACKING_UPDATED,
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_VERSION_SELECTED,
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_ACCESSED,
            ],
            array_column($timeline['events'], 'event_type'),
        );

        $this->assertSame('status_changed:'.$status->id, $timeline['events'][0]['event_key']);
        $this->assertSame($owner->id, $timeline['events'][0]['actor']['id']);
        $this->assertSame($owner->name, $timeline['events'][0]['actor']['name']);
        $this->assertSame('draft', $timeline['events'][0]['details']['from_status']);
        $this->assertSame('screening', $timeline['events'][0]['details']['status']);

        $this->assertSame($tracking->id, $timeline['events'][1]['source_id']);
        $this->assertSame('Email', $timeline['events'][1]['details']['before']['application_channel']);
        $this->assertSame('Company portal', $timeline['events'][1]['details']['after']['application_channel']);

        $this->assertSame($selection->id, $timeline['events'][2]['source_id']);
        $this->assertSame(11, $timeline['events'][2]['details']['before']['generated_document_version_id']);
        $this->assertSame(12, $timeline['events'][2]['details']['after']['generated_document_version_id']);

        $this->assertSame($access->id, $timeline['events'][3]['source_id']);
        $this->assertSame('submitted_snapshot', $timeline['events'][3]['details']['document_source']);
        $this->assertSame('targeted-cv.md', $timeline['events'][3]['details']['filename']);
        $this->assertArrayNotHasKey('storage_path', $timeline['events'][3]['details']);
        $this->assertArrayNotHasKey('_sort_timestamp', $timeline['events'][3]);
        $this->assertArrayNotHasKey('_sort_priority', $timeline['events'][3]);
    }

    public function test_same_timestamp_uses_stable_type_priority_and_source_id(): void
    {
        [$owner, $application] = $this->scenario();
        $timestamp = '2026-07-08 12:00:00';
        $this->accessHistory($application, $owner, $timestamp);
        $this->selectionHistory($application, $owner, $timestamp);
        $secondStatus = $this->statusHistory($application, $owner, $timestamp);
        $firstStatus = $this->statusHistory($application, $owner, $timestamp);
        $this->trackingHistory($application, $owner, $timestamp);

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
        );

        $this->assertSame(
            [
                JobApplicationTimelineBuilder::TYPE_STATUS_CHANGED,
                JobApplicationTimelineBuilder::TYPE_STATUS_CHANGED,
                JobApplicationTimelineBuilder::TYPE_TRACKING_UPDATED,
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_VERSION_SELECTED,
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_ACCESSED,
            ],
            array_column($timeline['events'], 'event_type'),
        );
        $this->assertSame(
            min($firstStatus->id, $secondStatus->id),
            $timeline['events'][0]['source_id'],
        );
        $this->assertSame(
            max($firstStatus->id, $secondStatus->id),
            $timeline['events'][1]['source_id'],
        );
    }

    public function test_default_direction_is_newest_first(): void
    {
        [$owner, $application] = $this->scenario();
        $this->statusHistory($application, $owner, '2026-07-08 09:00:00');
        $this->trackingHistory($application, $owner, '2026-07-08 10:00:00');
        $this->selectionHistory($application, $owner, '2026-07-08 11:00:00');

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
        );

        $this->assertSame('desc', $timeline['filters']['direction']);
        $this->assertSame(
            [
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_VERSION_SELECTED,
                JobApplicationTimelineBuilder::TYPE_TRACKING_UPDATED,
                JobApplicationTimelineBuilder::TYPE_STATUS_CHANGED,
            ],
            array_column($timeline['events'], 'event_type'),
        );
    }

    public function test_event_type_filter_preserves_available_and_returned_counts(): void
    {
        [$owner, $application] = $this->scenario();
        $this->statusHistory($application, $owner, '2026-07-08 09:00:00');
        $this->trackingHistory($application, $owner, '2026-07-08 10:00:00');
        $this->selectionHistory($application, $owner, '2026-07-08 11:00:00');
        $this->accessHistory($application, $owner, '2026-07-08 12:00:00');

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
            [
                'event_types' => [
                    JobApplicationTimelineBuilder::TYPE_TRACKING_UPDATED,
                    JobApplicationTimelineBuilder::TYPE_DOCUMENT_ACCESSED,
                ],
                'direction' => 'asc',
            ],
        );

        $this->assertSame(4, $timeline['summary']['available_total']);
        $this->assertSame(2, $timeline['summary']['matching_total']);
        $this->assertSame(2, $timeline['summary']['returned_total']);
        $this->assertSame(1, $timeline['summary']['available_by_type']['status_changed']);
        $this->assertSame(0, $timeline['summary']['returned_by_type']['status_changed']);
        $this->assertSame(1, $timeline['summary']['returned_by_type']['tracking_updated']);
        $this->assertSame(1, $timeline['summary']['returned_by_type']['document_accessed']);
        $this->assertSame(
            [
                JobApplicationTimelineBuilder::TYPE_TRACKING_UPDATED,
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_ACCESSED,
            ],
            array_column($timeline['events'], 'event_type'),
        );
    }

    public function test_limit_does_not_hide_matching_total(): void
    {
        [$owner, $application] = $this->scenario();
        $this->statusHistory($application, $owner, '2026-07-08 09:00:00');
        $this->trackingHistory($application, $owner, '2026-07-08 10:00:00');
        $this->selectionHistory($application, $owner, '2026-07-08 11:00:00');
        $this->accessHistory($application, $owner, '2026-07-08 12:00:00');

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
            ['limit' => 2],
        );

        $this->assertSame(4, $timeline['summary']['matching_total']);
        $this->assertSame(2, $timeline['summary']['returned_total']);
        $this->assertCount(2, $timeline['events']);
        $this->assertSame(
            [
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_ACCESSED,
                JobApplicationTimelineBuilder::TYPE_DOCUMENT_VERSION_SELECTED,
            ],
            array_column($timeline['events'], 'event_type'),
        );
    }

    public function test_deleted_or_unknown_actor_is_represented_as_null(): void
    {
        [$owner, $application] = $this->scenario();
        JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'from_status' => null,
            'status' => 'draft',
            'changed_by' => null,
            'changed_at' => '2026-07-08 09:00:00',
            'notes' => 'Imported legacy event.',
        ]);

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
        );

        $this->assertNull($timeline['events'][0]['actor']);
        $this->assertSame('Imported legacy event.', $timeline['events'][0]['details']['notes']);
    }

    public function test_exact_request_is_reproducible_and_does_not_write_data(): void
    {
        [$owner, $application] = $this->scenario();
        $this->statusHistory($application, $owner, '2026-07-08 09:00:00');
        $this->trackingHistory($application, $owner, '2026-07-08 10:00:00');
        $action = app(BuildJobApplicationTimeline::class);
        $input = [
            'event_types' => JobApplicationTimelineBuilder::EVENT_TYPES,
            'direction' => 'asc',
            'limit' => 100,
        ];

        $before = $this->historyCounts();
        $first = $action->execute($application, $owner, $input);
        $second = $action->execute($application, $owner, $input);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->historyCounts());
    }

    public function test_options_are_strictly_validated(): void
    {
        [$owner, $application] = $this->scenario();

        foreach ([
            ['event_types' => []],
            ['event_types' => ['unknown']],
            ['event_types' => ['status_changed', 'status_changed']],
            ['direction' => 'newest'],
            ['limit' => 0],
            ['limit' => 201],
            ['unknown' => true],
        ] as $input) {
            try {
                app(BuildJobApplicationTimeline::class)->execute(
                    $application,
                    $owner,
                    $input,
                );

                $this->fail('Invalid application timeline options were accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_user_cannot_build_another_users_application_timeline(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $outsider,
        );
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
            'application_channel' => 'Company portal',
            'external_reference' => 'APP-55',
            'applied_at' => '2026-07-01 09:00:00',
            'next_action_at' => '2026-07-10 09:00:00',
        ]);

        return [$owner, $application];
    }

    private function statusHistory(
        JobApplication $application,
        User $actor,
        string $changedAt,
    ): JobApplicationStatusHistory {
        return JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'from_status' => 'draft',
            'status' => 'screening',
            'changed_by' => $actor->id,
            'changed_at' => $changedAt,
            'notes' => 'Status progressed.',
        ]);
    }

    private function trackingHistory(
        JobApplication $application,
        User $actor,
        string $changedAt,
    ): JobApplicationTrackingHistory {
        return JobApplicationTrackingHistory::create([
            'job_application_id' => $application->id,
            'changed_by' => $actor->id,
            'change_source' => 'manual_update',
            'previous_application_channel' => 'Email',
            'application_channel' => 'Company portal',
            'previous_external_reference' => 'OLD-55',
            'external_reference' => 'APP-55',
            'previous_next_action_at' => '2026-07-09 09:00:00',
            'next_action_at' => '2026-07-10 09:00:00',
            'previous_notes' => 'Old tracking note.',
            'notes' => 'Updated tracking note.',
            'changed_at' => $changedAt,
        ]);
    }

    private function selectionHistory(
        JobApplication $application,
        User $actor,
        string $changedAt,
    ): JobApplicationDocumentVersionHistory {
        return JobApplicationDocumentVersionHistory::create([
            'job_application_id' => $application->id,
            'changed_by' => $actor->id,
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
            'changed_at' => $changedAt,
            'notes' => 'Selected final version.',
        ]);
    }

    private function accessHistory(
        JobApplication $application,
        User $actor,
        string $accessedAt,
    ): JobApplicationDocumentAccessHistory {
        return JobApplicationDocumentAccessHistory::create([
            'job_application_id' => $application->id,
            'accessed_by' => $actor->id,
            'document_source' => 'submitted_snapshot',
            'generated_document_version_id' => 12,
            'source_resume_version_id' => 22,
            'filename' => 'targeted-cv.md',
            'mime_type' => 'text/markdown',
            'file_size' => 128,
            'checksum_sha256' => str_repeat('b', 64),
            'storage_disk' => 'local',
            'storage_path' => 'generated-documents/private/path/targeted-cv.md',
            'accessed_at' => $accessedAt,
        ]);
    }

    private function historyCounts(): array
    {
        return [
            JobApplicationStatusHistory::query()->count(),
            JobApplicationTrackingHistory::query()->count(),
            JobApplicationDocumentVersionHistory::query()->count(),
            JobApplicationDocumentAccessHistory::query()->count(),
        ];
    }
}
