<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationFollowUpQueue;
use App\Models\JobApplication;
use App\Models\JobApplicationTrackingHistory;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildJobApplicationFollowUpQueueTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $referenceAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->referenceAt = CarbonImmutable::parse('2026-07-08 12:00:00');
    }

    public function test_queue_classifies_all_active_applications_and_excludes_terminal_or_foreign_ones(): void
    {
        [$owner, $profile] = $this->profile();
        $overdue = $this->application($profile, 'screening', '2026-07-07 18:00:00');
        $today = $this->application($profile, 'interview', '2026-07-08 16:00:00');
        $upcoming = $this->application($profile, 'assessment', '2026-07-12 09:00:00');
        $later = $this->application($profile, 'applied', '2026-07-20 09:00:00');
        $unscheduled = $this->application($profile, 'offer');
        $this->application($profile, 'hired', '2026-07-08 10:00:00');
        $this->application($profile, 'rejected');
        [, $otherProfile] = $this->profile();
        $this->application($otherProfile, 'screening', '2026-07-07 09:00:00');

        $queue = $this->build($profile, $owner);

        $this->assertSame(5, $queue['active_total']);
        $this->assertSame([$overdue->id], $this->ids($queue, 'overdue'));
        $this->assertSame([$today->id], $this->ids($queue, 'today'));
        $this->assertSame([$upcoming->id], $this->ids($queue, 'upcoming'));
        $this->assertSame([$later->id], $this->ids($queue, 'later'));
        $this->assertSame([$unscheduled->id], $this->ids($queue, 'unscheduled'));
        $this->assertSame('next_action_overdue', $queue['buckets']['overdue'][0]['reason_code']);
        $this->assertSame('active_application_without_next_action', $queue['buckets']['unscheduled'][0]['reason_code']);
    }

    public function test_scheduled_buckets_are_sorted_by_action_time_then_id(): void
    {
        [$owner, $profile] = $this->profile();
        $laterToday = $this->application($profile, 'screening', '2026-07-08 17:00:00');
        $firstToday = $this->application($profile, 'screening', '2026-07-08 09:00:00');
        $sameTimeFirst = $this->application($profile, 'screening', '2026-07-08 11:00:00');
        $sameTimeSecond = $this->application($profile, 'screening', '2026-07-08 11:00:00');

        $queue = $this->build($profile, $owner);

        $this->assertSame(
            [$firstToday->id, $sameTimeFirst->id, $sameTimeSecond->id, $laterToday->id],
            $this->ids($queue, 'today'),
        );
        $this->assertSame(0, $queue['buckets']['today'][0]['days_from_reference']);
    }

    public function test_unscheduled_bucket_uses_explicit_pipeline_priority(): void
    {
        [$owner, $profile] = $this->profile();
        $draft = $this->application($profile, 'draft');
        $applied = $this->application($profile, 'applied', null, '2026-07-04 09:00:00');
        $olderInterview = $this->application($profile, 'interview', null, '2026-07-03 09:00:00');
        $newerInterview = $this->application($profile, 'interview', null, '2026-07-06 09:00:00');
        $offer = $this->application($profile, 'offer', null, '2026-07-02 09:00:00');

        $queue = $this->build($profile, $owner);

        $this->assertSame(
            [$offer->id, $newerInterview->id, $olderInterview->id, $applied->id, $draft->id],
            $this->ids($queue, 'unscheduled'),
        );
    }

    public function test_custom_horizon_and_bucket_limit_preserve_total_counts(): void
    {
        [$owner, $profile] = $this->profile();
        $first = $this->application($profile, 'screening', '2026-07-09 08:00:00');
        $second = $this->application($profile, 'screening', '2026-07-10 08:00:00');
        $third = $this->application($profile, 'screening', '2026-07-11 08:00:00');

        $queue = $this->build($profile, $owner, [
            'upcoming_days' => 2,
            'limit_per_bucket' => 1,
        ]);

        $this->assertSame(2, $queue['summary']['upcoming']['total']);
        $this->assertSame(1, $queue['summary']['upcoming']['returned']);
        $this->assertSame([$first->id], $this->ids($queue, 'upcoming'));
        $this->assertSame(1, $queue['summary']['later']['total']);
        $this->assertSame([$third->id], $this->ids($queue, 'later'));
        $this->assertNotContains($second->id, $this->ids($queue, 'upcoming'));
    }

    public function test_queue_exposes_latest_tracking_change_and_application_snapshots(): void
    {
        [$owner, $profile] = $this->profile();
        $application = $this->application(
            $profile,
            'screening',
            '2026-07-08 15:00:00',
            '2026-07-01 10:00:00',
            [
                'job_title' => 'PHP Developer',
                'company_name' => 'Example SRL',
                'application_channel' => 'Company website',
                'external_reference' => 'APP-55',
            ],
        );
        $this->tracking($application, '2026-07-05 09:00:00');
        $this->tracking($application, '2026-07-07 18:30:00');

        $queue = $this->build($profile, $owner);
        $item = $queue['buckets']['today'][0];

        $this->assertSame('PHP Developer', $item['job_title']);
        $this->assertSame('Example SRL', $item['company_name']);
        $this->assertSame('Company website', $item['application_channel']);
        $this->assertSame('APP-55', $item['external_reference']);
        $this->assertSame(
            CarbonImmutable::parse('2026-07-07 18:30:00')->toISOString(),
            $item['latest_tracking_change_at'],
        );
    }

    public function test_exact_request_is_reproducible_and_does_not_write_data(): void
    {
        [$owner, $profile] = $this->profile();
        $this->application($profile, 'screening', '2026-07-08 15:00:00');
        $action = app(BuildJobApplicationFollowUpQueue::class);
        $input = [
            'reference_at' => $this->referenceAt->toDateTimeString(),
            'upcoming_days' => 7,
            'limit_per_bucket' => 25,
        ];

        $first = $action->execute($profile, $owner, $input);
        $second = $action->execute($profile, $owner, $input);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('job_applications', 1);
        $this->assertDatabaseCount('job_application_tracking_histories', 0);
        $this->assertDatabaseCount('job_application_status_histories', 0);
    }

    public function test_options_are_strictly_validated(): void
    {
        [$owner, $profile] = $this->profile();

        foreach ([
            ['upcoming_days' => 0],
            ['upcoming_days' => 31],
            ['limit_per_bucket' => 0],
            ['limit_per_bucket' => 101],
            ['reference_at' => 'not-a-date'],
            ['unknown' => true],
        ] as $input) {
            try {
                app(BuildJobApplicationFollowUpQueue::class)->execute(
                    $profile,
                    $owner,
                    $input,
                );

                $this->fail('Invalid follow-up queue options were accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_user_cannot_build_another_users_queue(): void
    {
        [, $profile] = $this->profile();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(BuildJobApplicationFollowUpQueue::class)->execute(
            $profile,
            $outsider,
            ['reference_at' => $this->referenceAt->toDateTimeString()],
        );
    }

    private function build(Profile $profile, User $owner, array $overrides = []): array
    {
        return app(BuildJobApplicationFollowUpQueue::class)->execute(
            $profile,
            $owner,
            array_merge([
                'reference_at' => $this->referenceAt->toDateTimeString(),
                'upcoming_days' => 7,
                'limit_per_bucket' => 25,
            ], $overrides),
        );
    }

    private function profile(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);

        return [$owner, $profile];
    }

    private function application(
        Profile $profile,
        string $status,
        ?string $nextActionAt = null,
        ?string $appliedAt = null,
        array $overrides = [],
    ): JobApplication {
        return JobApplication::create(array_merge([
            'profile_id' => $profile->id,
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => $status,
            'applied_at' => $appliedAt,
            'next_action_at' => $nextActionAt,
        ], $overrides));
    }

    private function tracking(JobApplication $application, string $changedAt): void
    {
        JobApplicationTrackingHistory::create([
            'job_application_id' => $application->id,
            'change_source' => 'manual_update',
            'changed_at' => $changedAt,
        ]);
    }

    private function ids(array $queue, string $bucket): array
    {
        return array_column($queue['buckets'][$bucket], 'application_id');
    }
}
