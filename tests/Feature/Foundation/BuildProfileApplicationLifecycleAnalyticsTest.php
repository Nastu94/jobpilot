<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationLifecycleAnalytics;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildProfileApplicationLifecycleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_mixed_portfolio_calculates_population_milestones_outcomes_and_durations(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->application($profile, 'draft', 'draft');
        $this->application(
            $profile,
            'withdrawn-before-apply',
            'withdrawn',
            null,
            [
                ['draft', 'withdrawn', '2026-07-02 08:00:00'],
            ],
        );
        $this->application(
            $profile,
            'hired',
            'hired',
            '2026-07-01 09:00:00',
            [
                ['draft', 'applied', '2026-07-01 09:00:00'],
                ['applied', 'screening', '2026-07-02 09:00:00'],
                ['screening', 'interview', '2026-07-04 09:00:00'],
                ['interview', 'offer', '2026-07-05 09:00:00'],
                ['offer', 'hired', '2026-07-06 09:00:00'],
            ],
        );
        $this->application(
            $profile,
            'rejected',
            'rejected',
            '2026-07-02 09:00:00',
            [
                ['draft', 'applied', '2026-07-02 09:00:00'],
                ['applied', 'assessment', '2026-07-03 09:00:00'],
                ['assessment', 'interview', '2026-07-05 09:00:00'],
                ['interview', 'rejected', '2026-07-07 09:00:00'],
            ],
        );
        $this->application(
            $profile,
            'active-applied',
            'applied',
            '2026-07-08 09:00:00',
            [
                ['draft', 'applied', '2026-07-08 09:00:00'],
            ],
        );

        $analytics = app(BuildProfileApplicationLifecycleAnalytics::class)
            ->execute(
                $profile,
                $owner,
                ['reference_at' => '2026-07-10 12:00:00'],
            );
        $milestones = collect($analytics['milestones'])->keyBy('status');
        $durations = collect($analytics['stage_durations'])->keyBy('status');
        $routes = collect($analytics['transitions']['routes'])
            ->keyBy(fn (array $route): string => $route['from_status'].'->'.$route['to_status']);

        $this->assertSame($profile->id, $analytics['profile_id']);
        $this->assertSame('2026-07-10T12:00:00.000000Z', $analytics['reference_at']);
        $this->assertSame([
            'applications_total' => 5,
            'drafts_total' => 1,
            'not_submitted_total' => 2,
            'non_submitted_terminal_total' => 1,
            'submitted_total' => 3,
            'eligible_total' => 3,
            'excluded_total' => 0,
            'active_eligible_total' => 1,
            'terminal_eligible_total' => 2,
        ], $analytics['population']);
        $this->assertSame([], $analytics['exclusions']);

        $this->assertSame(3, $milestones['applied']['reached_total']);
        $this->assertSame(100.0, $milestones['applied']['rate_from_eligible_submitted_percent']);
        $this->assertSame(1, $milestones['screening']['reached_total']);
        $this->assertSame(1, $milestones['assessment']['reached_total']);
        $this->assertSame(2, $milestones['interview']['reached_total']);
        $this->assertSame(66.67, $milestones['interview']['rate_from_eligible_submitted_percent']);
        $this->assertSame(72.0, $milestones['interview']['time_from_application']['average_hours']);
        $this->assertSame(1, $milestones['offer']['reached_total']);
        $this->assertSame(1, $milestones['hired']['reached_total']);
        $this->assertSame(120.0, $milestones['hired']['time_from_application']['average_hours']);

        $this->assertSame(2, $analytics['outcomes']['terminal_total']);
        $this->assertSame(1, $analytics['outcomes']['pending_total']);
        $this->assertSame(66.67, $analytics['outcomes']['terminal_rate_percent']);
        $this->assertSame(1, $analytics['outcomes']['by_status']['hired']['total']);
        $this->assertSame(50.0, $analytics['outcomes']['by_status']['hired']['rate_from_terminal_percent']);
        $this->assertSame(1, $analytics['outcomes']['by_status']['rejected']['total']);
        $this->assertSame(0, $analytics['outcomes']['by_status']['withdrawn']['total']);

        $this->assertSame(10, $analytics['transitions']['events_total']);
        $this->assertSame(3, $routes['draft->applied']['total']);
        $this->assertSame(1, $routes['applied->screening']['total']);
        $this->assertSame(1, $routes['applied->assessment']['total']);

        $this->assertSame(2, $durations['applied']['completed_intervals']['sample_count']);
        $this->assertSame(24.0, $durations['applied']['completed_intervals']['average_hours']);
        $this->assertSame(1, $durations['applied']['open_intervals']['sample_count']);
        $this->assertSame(51.0, $durations['applied']['open_intervals']['average_hours']);
        $this->assertSame(1, $durations['screening']['completed_intervals']['sample_count']);
        $this->assertSame(48.0, $durations['screening']['completed_intervals']['average_hours']);
        $this->assertSame(2, $durations['interview']['completed_intervals']['sample_count']);
        $this->assertSame(36.0, $durations['interview']['completed_intervals']['average_hours']);
        $this->assertSame(36.0, $durations['interview']['completed_intervals']['median_hours']);
        $this->assertSame(24.0, $durations['interview']['completed_intervals']['minimum_hours']);
        $this->assertSame(48.0, $durations['interview']['completed_intervals']['maximum_hours']);
    }

    public function test_skipped_stages_remain_explicit_in_milestones_and_routes(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->application(
            $profile,
            'direct-interview',
            'hired',
            '2026-07-01 09:00:00',
            [
                ['draft', 'applied', '2026-07-01 09:00:00'],
                ['applied', 'interview', '2026-07-02 09:00:00'],
                ['interview', 'offer', '2026-07-03 09:00:00'],
                ['offer', 'hired', '2026-07-04 09:00:00'],
            ],
        );

        $analytics = app(BuildProfileApplicationLifecycleAnalytics::class)
            ->execute($profile, $owner);
        $milestones = collect($analytics['milestones'])->keyBy('status');
        $routes = collect($analytics['transitions']['routes'])
            ->keyBy(fn (array $route): string => $route['from_status'].'->'.$route['to_status']);

        $this->assertSame(0, $milestones['screening']['reached_total']);
        $this->assertSame(0.0, $milestones['screening']['rate_from_eligible_submitted_percent']);
        $this->assertSame(0, $milestones['assessment']['reached_total']);
        $this->assertSame(1, $milestones['interview']['reached_total']);
        $this->assertSame(100.0, $milestones['interview']['rate_from_eligible_submitted_percent']);
        $this->assertSame(1, $routes['applied->interview']['total']);
        $this->assertArrayNotHasKey('applied->screening', $routes->all());
        $this->assertArrayNotHasKey('screening->interview', $routes->all());
    }

    public function test_invalid_submitted_histories_are_excluded_with_explicit_reasons(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $missing = $this->application(
            $profile,
            'missing-history',
            'applied',
            '2026-07-01 09:00:00',
        );
        $broken = $this->application(
            $profile,
            'broken-chain',
            'interview',
            '2026-07-02 09:00:00',
            [
                ['draft', 'applied', '2026-07-02 09:00:00'],
                ['screening', 'interview', '2026-07-03 09:00:00'],
            ],
        );
        $future = $this->application(
            $profile,
            'future-history',
            'screening',
            '2026-07-09 09:00:00',
            [
                ['draft', 'applied', '2026-07-09 09:00:00'],
                ['applied', 'screening', '2026-07-11 09:00:00'],
            ],
        );
        $mismatch = $this->application(
            $profile,
            'applied-mismatch',
            'applied',
            '2026-07-04 09:00:00',
            [
                ['draft', 'applied', '2026-07-05 09:00:00'],
            ],
        );

        $analytics = app(BuildProfileApplicationLifecycleAnalytics::class)
            ->execute(
                $profile,
                $owner,
                ['reference_at' => '2026-07-10 12:00:00'],
            );
        $exclusions = collect($analytics['exclusions'])->keyBy('reason_code');

        $this->assertSame(4, $analytics['population']['submitted_total']);
        $this->assertSame(0, $analytics['population']['eligible_total']);
        $this->assertSame(4, $analytics['population']['excluded_total']);
        $this->assertSame(
            [$missing->id],
            $exclusions['missing_status_history']['application_ids'],
        );
        $this->assertSame(
            [$broken->id],
            $exclusions['history_chain_broken']['application_ids'],
        );
        $this->assertSame(
            [$future->id],
            $exclusions['history_after_reference']['application_ids'],
        );
        $this->assertSame(
            [$mismatch->id],
            $exclusions['applied_at_history_mismatch']['application_ids'],
        );
        $this->assertNull(
            collect($analytics['milestones'])
                ->firstWhere('status', 'applied')['rate_from_eligible_submitted_percent'],
        );
        $this->assertSame(0, $analytics['transitions']['events_total']);
    }

    public function test_non_submitted_drafts_and_withdrawals_are_not_analytics_exclusions(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->application($profile, 'draft', 'draft');
        $this->application(
            $profile,
            'withdrawn',
            'withdrawn',
            null,
            [['draft', 'withdrawn', '2026-07-02 09:00:00']],
        );

        $analytics = app(BuildProfileApplicationLifecycleAnalytics::class)
            ->execute($profile, $owner);

        $this->assertSame(2, $analytics['population']['applications_total']);
        $this->assertSame(2, $analytics['population']['not_submitted_total']);
        $this->assertSame(1, $analytics['population']['drafts_total']);
        $this->assertSame(1, $analytics['population']['non_submitted_terminal_total']);
        $this->assertSame(0, $analytics['population']['submitted_total']);
        $this->assertSame(0, $analytics['population']['excluded_total']);
        $this->assertSame([], $analytics['exclusions']);
    }

    public function test_empty_profile_returns_stable_zero_analytics(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $analytics = app(BuildProfileApplicationLifecycleAnalytics::class)
            ->execute($profile, $owner);

        $this->assertSame(0, $analytics['population']['applications_total']);
        $this->assertSame(0, $analytics['population']['eligible_total']);
        $this->assertSame([], $analytics['exclusions']);
        $this->assertSame(0, $analytics['outcomes']['terminal_total']);
        $this->assertNull($analytics['outcomes']['terminal_rate_percent']);
        $this->assertSame(0, $analytics['transitions']['events_total']);
        $this->assertSame([], $analytics['transitions']['routes']);
        $this->assertSame(0, $analytics['stage_durations'][0]['completed_intervals']['sample_count']);
    }

    public function test_options_are_strictly_validated_and_future_reference_is_rejected(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $action = app(BuildProfileApplicationLifecycleAnalytics::class);
        $invalidInputs = [
            ['unknown' => true],
            ['reference_at' => 'not-a-date'],
            ['reference_at' => '2026-07-11 12:00:00'],
        ];

        foreach ($invalidInputs as $input) {
            try {
                $action->execute($profile, $owner, $input);

                $this->fail('Invalid lifecycle analytics options were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_analytics_are_deterministic_and_read_only(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $application = $this->application(
            $profile,
            'deterministic',
            'applied',
            '2026-07-08 09:00:00',
            [['draft', 'applied', '2026-07-08 09:00:00']],
        );
        $before = $this->databaseCounts();
        $action = app(BuildProfileApplicationLifecycleAnalytics::class);

        $first = $action->execute($profile, $owner);
        $second = $action->execute($profile, $owner);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame('applied', $application->fresh()->status);
    }

    public function test_outsider_cannot_build_lifecycle_analytics(): void
    {
        [, $profile] = $this->ownerAndProfile();

        $this->expectException(AuthorizationException::class);

        app(BuildProfileApplicationLifecycleAnalytics::class)->execute(
            $profile,
            User::factory()->create(),
        );
    }

    private function ownerAndProfile(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);

        return [$owner, $profile];
    }

    private function application(
        Profile $profile,
        string $suffix,
        string $status,
        ?string $appliedAt = null,
        array $history = [],
    ): JobApplication {
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Role '.$suffix,
            'company_name' => 'Company '.$suffix,
            'status' => $status,
            'applied_at' => $appliedAt,
        ]);

        foreach ($history as [$fromStatus, $toStatus, $changedAt]) {
            $application->statusHistory()->create([
                'from_status' => $fromStatus,
                'status' => $toStatus,
                'changed_by' => $profile->user_id,
                'changed_at' => $changedAt,
            ]);
        }

        return $application->fresh('statusHistory');
    }

    private function databaseCounts(): array
    {
        return [
            'applications' => JobApplication::query()->count(),
            'status_histories' => DB::table('job_application_status_histories')->count(),
            'tracking_histories' => DB::table('job_application_tracking_histories')->count(),
            'submission_confirmations' => DB::table('job_application_submission_confirmations')->count(),
            'scheduled_events' => DB::table('job_application_scheduled_events')->count(),
            'interactions' => DB::table('job_application_interactions')->count(),
        ];
    }
}
