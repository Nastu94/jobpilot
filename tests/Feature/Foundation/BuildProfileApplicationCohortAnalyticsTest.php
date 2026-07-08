<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationCohortAnalytics;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildProfileApplicationCohortAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-03-31 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_monthly_cohorts_include_empty_periods_conversions_outcomes_and_times(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->application(
            $profile,
            'jan-hired',
            'hired',
            '2026-01-05 09:00:00',
            [
                ['draft', 'applied', '2026-01-05 09:00:00'],
                ['applied', 'interview', '2026-01-07 09:00:00'],
                ['interview', 'offer', '2026-01-08 09:00:00'],
                ['offer', 'hired', '2026-01-10 09:00:00'],
            ],
        );
        $this->application(
            $profile,
            'jan-active',
            'screening',
            '2026-01-20 09:00:00',
            [
                ['draft', 'applied', '2026-01-20 09:00:00'],
                ['applied', 'screening', '2026-01-21 09:00:00'],
            ],
        );
        $this->application(
            $profile,
            'feb-rejected',
            'rejected',
            '2026-02-10 09:00:00',
            [
                ['draft', 'applied', '2026-02-10 09:00:00'],
                ['applied', 'assessment', '2026-02-11 09:00:00'],
                ['assessment', 'interview', '2026-02-12 09:00:00'],
                ['interview', 'rejected', '2026-02-15 09:00:00'],
            ],
        );
        $invalid = $this->application(
            $profile,
            'feb-invalid',
            'applied',
            '2026-02-20 09:00:00',
        );

        $analytics = app(BuildProfileApplicationCohortAnalytics::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-03-31 12:00:00',
                'start_at' => '2026-01-01 00:00:00',
                'end_at' => '2026-03-31 12:00:00',
                'granularity' => 'month',
            ],
        );
        $buckets = collect($analytics['buckets'])->keyBy('period_key');

        $this->assertSame($profile->id, $analytics['profile_id']);
        $this->assertSame([
            'start_at' => '2026-01-01T00:00:00.000000Z',
            'end_at' => '2026-03-31T12:00:00.000000Z',
            'granularity' => 'month',
            'periods_total' => 3,
        ], $analytics['range']);
        $this->assertSame([
            'submitted_in_range_total' => 4,
            'eligible_total' => 3,
            'excluded_total' => 1,
        ], $analytics['population']);
        $this->assertSame(
            [$invalid->id],
            $analytics['exclusions'][0]['application_ids'],
        );
        $this->assertSame(
            'missing_status_history',
            $analytics['exclusions'][0]['reason_code'],
        );

        $this->assertSame(2, $buckets['2026-01']['submitted_total']);
        $this->assertSame(1, $buckets['2026-01']['active_total']);
        $this->assertSame(1, $buckets['2026-01']['terminal_total']);
        $this->assertSame(1, $buckets['2026-01']['milestones']['screening']['reached_total']);
        $this->assertSame(50.0, $buckets['2026-01']['milestones']['screening']['conversion_percent']);
        $this->assertSame(1, $buckets['2026-01']['milestones']['interview']['reached_total']);
        $this->assertSame(48.0, $buckets['2026-01']['milestones']['interview']['time_from_application']['average_hours']);
        $this->assertSame(72.0, $buckets['2026-01']['milestones']['offer']['time_from_application']['average_hours']);
        $this->assertSame(120.0, $buckets['2026-01']['milestones']['hired']['time_from_application']['average_hours']);
        $this->assertSame(1, $buckets['2026-01']['outcomes']['hired']['total']);
        $this->assertSame(50.0, $buckets['2026-01']['outcomes']['hired']['rate_from_submitted_percent']);
        $this->assertSame(100.0, $buckets['2026-01']['outcomes']['hired']['rate_from_terminal_percent']);

        $this->assertSame(1, $buckets['2026-02']['submitted_total']);
        $this->assertSame(1, $buckets['2026-02']['terminal_total']);
        $this->assertSame(1, $buckets['2026-02']['milestones']['assessment']['reached_total']);
        $this->assertSame(1, $buckets['2026-02']['milestones']['interview']['reached_total']);
        $this->assertSame(48.0, $buckets['2026-02']['milestones']['interview']['time_from_application']['average_hours']);
        $this->assertSame(1, $buckets['2026-02']['outcomes']['rejected']['total']);

        $this->assertSame(0, $buckets['2026-03']['submitted_total']);
        $this->assertNull($buckets['2026-03']['milestones']['interview']['conversion_percent']);
        $this->assertNull($buckets['2026-03']['outcomes']['hired']['rate_from_terminal_percent']);

        $this->assertSame(3, $analytics['totals']['submitted_total']);
        $this->assertSame(1, $analytics['totals']['active_total']);
        $this->assertSame(2, $analytics['totals']['terminal_total']);
        $this->assertSame(2, $analytics['totals']['milestones']['interview']['reached_total']);
        $this->assertSame(66.67, $analytics['totals']['milestones']['interview']['conversion_percent']);
        $this->assertSame(48.0, $analytics['totals']['milestones']['interview']['time_from_application']['average_hours']);
        $this->assertSame(1, $analytics['totals']['outcomes']['hired']['total']);
        $this->assertSame(1, $analytics['totals']['outcomes']['rejected']['total']);
    }

    public function test_weekly_cohorts_use_monday_boundaries(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $monday = $this->appliedApplication(
            $profile,
            'monday',
            '2026-03-02 09:00:00',
        );
        $sunday = $this->appliedApplication(
            $profile,
            'sunday',
            '2026-03-08 18:00:00',
        );
        $nextMonday = $this->appliedApplication(
            $profile,
            'next-monday',
            '2026-03-09 09:00:00',
        );

        $analytics = app(BuildProfileApplicationCohortAnalytics::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-03-20 12:00:00',
                'start_at' => '2026-03-02 00:00:00',
                'end_at' => '2026-03-15 23:59:59',
                'granularity' => 'week',
            ],
        );

        $this->assertSame(
            ['2026-W10', '2026-W11'],
            array_column($analytics['buckets'], 'period_key'),
        );
        $this->assertSame(2, $analytics['buckets'][0]['submitted_total']);
        $this->assertSame(1, $analytics['buckets'][1]['submitted_total']);
        $this->assertSame(3, $analytics['totals']['submitted_total']);
        $this->assertSame('applied', $monday->fresh()->status);
        $this->assertSame('applied', $sunday->fresh()->status);
        $this->assertSame('applied', $nextMonday->fresh()->status);
    }

    public function test_range_excludes_submissions_outside_boundaries(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->appliedApplication($profile, 'before', '2025-12-31 23:59:59');
        $inside = $this->appliedApplication($profile, 'inside', '2026-01-15 09:00:00');
        $this->appliedApplication($profile, 'after', '2026-02-01 00:00:01');

        $analytics = app(BuildProfileApplicationCohortAnalytics::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-03-31 12:00:00',
                'start_at' => '2026-01-01 00:00:00',
                'end_at' => '2026-01-31 23:59:59',
            ],
        );

        $this->assertSame(1, $analytics['population']['submitted_in_range_total']);
        $this->assertSame(1, $analytics['population']['eligible_total']);
        $this->assertSame(1, $analytics['totals']['submitted_total']);
        $this->assertSame('applied', $inside->fresh()->status);
    }

    public function test_empty_range_returns_all_periods_with_zero_metrics(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $analytics = app(BuildProfileApplicationCohortAnalytics::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-03-31 12:00:00',
                'start_at' => '2026-01-01 00:00:00',
                'end_at' => '2026-03-31 12:00:00',
            ],
        );

        $this->assertSame(3, $analytics['range']['periods_total']);
        $this->assertSame(0, $analytics['population']['submitted_in_range_total']);
        $this->assertSame(0, $analytics['totals']['submitted_total']);
        $this->assertSame(3, count($analytics['buckets']));
        $this->assertSame([0, 0, 0], array_column(
            $analytics['buckets'],
            'submitted_total',
        ));
        $this->assertNull($analytics['totals']['milestones']['offer']['conversion_percent']);
        $this->assertSame([], $analytics['exclusions']);
    }

    public function test_default_monthly_range_contains_twelve_periods(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $analytics = app(BuildProfileApplicationCohortAnalytics::class)->execute(
            $profile,
            $owner,
        );

        $this->assertSame('month', $analytics['range']['granularity']);
        $this->assertSame(12, $analytics['range']['periods_total']);
        $this->assertSame('2025-04', $analytics['buckets'][0]['period_key']);
        $this->assertSame('2026-03', $analytics['buckets'][11]['period_key']);
    }

    public function test_options_and_period_limit_are_strictly_validated(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $action = app(BuildProfileApplicationCohortAnalytics::class);
        $invalidInputs = [
            ['unknown' => true],
            ['reference_at' => 'not-a-date'],
            ['reference_at' => '2026-04-01 12:00:00'],
            ['granularity' => 'quarter'],
            [
                'reference_at' => '2026-03-31 12:00:00',
                'start_at' => '2026-03-02 00:00:00',
                'end_at' => '2026-03-01 00:00:00',
            ],
            [
                'reference_at' => '2026-03-31 12:00:00',
                'start_at' => '2026-03-01 00:00:00',
                'end_at' => '2026-04-01 00:00:00',
            ],
            [
                'reference_at' => '2026-03-31 12:00:00',
                'start_at' => '2020-01-01 00:00:00',
                'end_at' => '2026-03-31 12:00:00',
                'granularity' => 'month',
            ],
            [
                'reference_at' => '2026-03-31 12:00:00',
                'start_at' => '2025-01-01 00:00:00',
                'end_at' => '2026-03-31 12:00:00',
                'granularity' => 'week',
            ],
        ];

        foreach ($invalidInputs as $input) {
            try {
                $action->execute($profile, $owner, $input);

                $this->fail('Invalid cohort analytics options were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_cohort_analytics_are_deterministic_and_read_only(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $application = $this->appliedApplication(
            $profile,
            'deterministic',
            '2026-03-01 09:00:00',
        );
        $before = $this->databaseCounts();
        $action = app(BuildProfileApplicationCohortAnalytics::class);
        $options = [
            'reference_at' => '2026-03-31 12:00:00',
            'start_at' => '2026-03-01 00:00:00',
            'end_at' => '2026-03-31 12:00:00',
        ];

        $first = $action->execute($profile, $owner, $options);
        $second = $action->execute($profile, $owner, $options);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame('applied', $application->fresh()->status);
    }

    public function test_outsider_cannot_build_cohort_analytics(): void
    {
        [, $profile] = $this->ownerAndProfile();

        $this->expectException(AuthorizationException::class);

        app(BuildProfileApplicationCohortAnalytics::class)->execute(
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

    private function appliedApplication(
        Profile $profile,
        string $suffix,
        string $appliedAt,
    ): JobApplication {
        return $this->application(
            $profile,
            $suffix,
            'applied',
            $appliedAt,
            [['draft', 'applied', $appliedAt]],
        );
    }

    private function application(
        Profile $profile,
        string $suffix,
        string $status,
        string $appliedAt,
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
