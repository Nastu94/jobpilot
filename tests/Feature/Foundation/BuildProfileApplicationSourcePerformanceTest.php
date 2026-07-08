<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationSourcePerformance;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildProfileApplicationSourcePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-31 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_job_source_groups_are_normalized_and_include_performance_and_exclusions(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->application(
            $profile,
            'linkedin-hired',
            'LinkedIn',
            null,
            'hired',
            '2026-06-01 09:00:00',
            [
                ['draft', 'applied', '2026-06-01 09:00:00'],
                ['applied', 'interview', '2026-06-03 09:00:00'],
                ['interview', 'offer', '2026-06-04 09:00:00'],
                ['offer', 'hired', '2026-06-06 09:00:00'],
            ],
        );
        $this->application(
            $profile,
            'linkedin-active',
            '  linkedin  ',
            null,
            'screening',
            '2026-06-10 09:00:00',
            [
                ['draft', 'applied', '2026-06-10 09:00:00'],
                ['applied', 'screening', '2026-06-11 09:00:00'],
            ],
        );
        $invalid = $this->application(
            $profile,
            'linkedin-invalid',
            'LINKEDIN',
            null,
            'applied',
            '2026-06-20 09:00:00',
        );
        $this->application(
            $profile,
            'company-rejected',
            'company_site',
            null,
            'rejected',
            '2026-07-01 09:00:00',
            [
                ['draft', 'applied', '2026-07-01 09:00:00'],
                ['applied', 'assessment', '2026-07-02 09:00:00'],
                ['assessment', 'rejected', '2026-07-04 09:00:00'],
            ],
        );
        $this->application(
            $profile,
            'unknown-active',
            null,
            null,
            'applied',
            '2026-07-10 09:00:00',
            [['draft', 'applied', '2026-07-10 09:00:00']],
            false,
        );

        $analytics = app(BuildProfileApplicationSourcePerformance::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-07-31 12:00:00',
                'start_at' => '2026-06-01 00:00:00',
                'end_at' => '2026-07-31 12:00:00',
            ],
        );
        $groups = collect($analytics['groups'])->keyBy('group_key');

        $this->assertSame('job_source', $analytics['dimension']);
        $this->assertSame('descriptive_not_causal', $analytics['methodology']['interpretation']);
        $this->assertSame([
            'submitted_in_range_total' => 5,
            'eligible_total' => 4,
            'excluded_total' => 1,
            'groups_total' => 3,
        ], $analytics['population']);
        $this->assertSame(
            ['linkedin', 'company_site', 'unknown'],
            array_column($analytics['groups'], 'group_key'),
        );
        $this->assertSame(3, $groups['linkedin']['submitted_in_range_total']);
        $this->assertSame(2, $groups['linkedin']['eligible_total']);
        $this->assertSame(1, $groups['linkedin']['excluded_total']);
        $this->assertSame(
            [$invalid->id],
            $groups['linkedin']['exclusions'][0]['application_ids'],
        );
        $this->assertSame(2, $groups['linkedin']['performance']['submitted_total']);
        $this->assertSame(1, $groups['linkedin']['performance']['active_total']);
        $this->assertSame(1, $groups['linkedin']['performance']['terminal_total']);
        $this->assertSame(1, $groups['linkedin']['performance']['milestones']['interview']['reached_total']);
        $this->assertSame(50.0, $groups['linkedin']['performance']['milestones']['interview']['conversion_percent']);
        $this->assertSame(48.0, $groups['linkedin']['performance']['milestones']['interview']['time_from_application']['average_hours']);
        $this->assertSame(1, $groups['linkedin']['performance']['outcomes']['hired']['total']);
        $this->assertSame(50.0, $groups['linkedin']['performance']['outcomes']['hired']['rate_from_submitted_percent']);
        $this->assertSame(1, $groups['company_site']['performance']['outcomes']['rejected']['total']);
        $this->assertSame(1, $groups['unknown']['performance']['active_total']);
        $this->assertSame(4, $analytics['totals']['submitted_total']);
        $this->assertSame(1, $analytics['exclusions'][0]['total']);
    }

    public function test_application_channel_dimension_merges_normalized_values_and_unknown(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->application(
            $profile,
            'channel-one',
            'linkedin',
            'Company Website',
            'applied',
            '2026-07-01 09:00:00',
            [['draft', 'applied', '2026-07-01 09:00:00']],
        );
        $this->application(
            $profile,
            'channel-two',
            'indeed',
            ' company   website ',
            'rejected',
            '2026-07-02 09:00:00',
            [
                ['draft', 'applied', '2026-07-02 09:00:00'],
                ['applied', 'rejected', '2026-07-05 09:00:00'],
            ],
        );
        $this->application(
            $profile,
            'channel-unknown',
            'referral',
            null,
            'applied',
            '2026-07-03 09:00:00',
            [['draft', 'applied', '2026-07-03 09:00:00']],
        );

        $analytics = app(BuildProfileApplicationSourcePerformance::class)->execute(
            $profile,
            $owner,
            [
                'dimension' => 'application_channel',
                'start_at' => '2026-07-01 00:00:00',
                'end_at' => '2026-07-31 12:00:00',
            ],
        );
        $groups = collect($analytics['groups'])->keyBy('group_key');

        $this->assertSame('application_channel', $analytics['dimension']);
        $this->assertSame(2, $groups['company website']['submitted_in_range_total']);
        $this->assertSame(2, $groups['company website']['eligible_total']);
        $this->assertSame(1, $groups['company website']['performance']['outcomes']['rejected']['total']);
        $this->assertSame(1, $groups['unknown']['submitted_in_range_total']);
        $this->assertSame(2, $analytics['population']['groups_total']);
    }

    public function test_range_excludes_submissions_outside_boundaries(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->appliedApplication($profile, 'before', 'linkedin', '2026-05-31 23:59:59');
        $inside = $this->appliedApplication($profile, 'inside', 'linkedin', '2026-06-15 09:00:00');
        $this->appliedApplication($profile, 'after', 'linkedin', '2026-07-01 00:00:01');

        $analytics = app(BuildProfileApplicationSourcePerformance::class)->execute(
            $profile,
            $owner,
            [
                'start_at' => '2026-06-01 00:00:00',
                'end_at' => '2026-06-30 23:59:59',
            ],
        );

        $this->assertSame(1, $analytics['population']['submitted_in_range_total']);
        $this->assertSame(1, $analytics['population']['eligible_total']);
        $this->assertSame(1, $analytics['groups'][0]['submitted_in_range_total']);
        $this->assertSame('applied', $inside->fresh()->status);
    }

    public function test_empty_range_returns_stable_zero_performance(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $analytics = app(BuildProfileApplicationSourcePerformance::class)->execute(
            $profile,
            $owner,
            [
                'start_at' => '2026-06-01 00:00:00',
                'end_at' => '2026-07-31 12:00:00',
            ],
        );

        $this->assertSame(0, $analytics['population']['submitted_in_range_total']);
        $this->assertSame(0, $analytics['population']['groups_total']);
        $this->assertSame([], $analytics['groups']);
        $this->assertSame([], $analytics['exclusions']);
        $this->assertSame(0, $analytics['totals']['submitted_total']);
        $this->assertNull($analytics['totals']['milestones']['offer']['conversion_percent']);
    }

    public function test_default_range_covers_twelve_calendar_months(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $analytics = app(BuildProfileApplicationSourcePerformance::class)->execute(
            $profile,
            $owner,
        );

        $this->assertSame('2025-08-01T00:00:00.000000Z', $analytics['range']['start_at']);
        $this->assertSame('2026-07-31T12:00:00.000000Z', $analytics['range']['end_at']);
        $this->assertSame('job_source', $analytics['dimension']);
    }

    public function test_options_and_range_are_strictly_validated(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $action = app(BuildProfileApplicationSourcePerformance::class);
        $invalidInputs = [
            ['unknown' => true],
            ['dimension' => 'company'],
            ['reference_at' => 'not-a-date'],
            ['reference_at' => '2026-08-01 12:00:00'],
            [
                'start_at' => '2026-07-02 00:00:00',
                'end_at' => '2026-07-01 00:00:00',
            ],
            [
                'reference_at' => '2026-07-31 12:00:00',
                'start_at' => '2026-07-01 00:00:00',
                'end_at' => '2026-08-01 00:00:00',
            ],
            [
                'reference_at' => '2026-07-31 12:00:00',
                'start_at' => '2020-01-01 00:00:00',
                'end_at' => '2026-07-31 12:00:00',
            ],
        ];

        foreach ($invalidInputs as $input) {
            try {
                $action->execute($profile, $owner, $input);

                $this->fail('Invalid source performance options were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_source_performance_is_deterministic_and_read_only(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $application = $this->appliedApplication(
            $profile,
            'deterministic',
            'linkedin',
            '2026-07-01 09:00:00',
        );
        $before = $this->databaseCounts();
        $action = app(BuildProfileApplicationSourcePerformance::class);

        $first = $action->execute($profile, $owner);
        $second = $action->execute($profile, $owner);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame('applied', $application->fresh()->status);
    }

    public function test_outsider_cannot_build_source_performance(): void
    {
        [, $profile] = $this->ownerAndProfile();

        $this->expectException(AuthorizationException::class);

        app(BuildProfileApplicationSourcePerformance::class)->execute(
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
        ?string $source,
        string $appliedAt,
    ): JobApplication {
        return $this->application(
            $profile,
            $suffix,
            $source,
            null,
            'applied',
            $appliedAt,
            [['draft', 'applied', $appliedAt]],
        );
    }

    private function application(
        Profile $profile,
        string $suffix,
        ?string $source,
        ?string $channel,
        string $status,
        string $appliedAt,
        array $history = [],
        bool $withPosting = true,
    ): JobApplication {
        $posting = $withPosting
            ? JobPosting::create([
                'profile_id' => $profile->id,
                'title' => 'Role '.$suffix,
                'company_name' => 'Company '.$suffix,
                'source' => $source,
                'source_url' => 'https://jobs.example.com/'.$suffix,
            ])
            : null;
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting?->id,
            'job_title' => 'Role '.$suffix,
            'company_name' => 'Company '.$suffix,
            'status' => $status,
            'applied_at' => $appliedAt,
            'application_channel' => $channel,
        ]);

        foreach ($history as [$fromStatus, $toStatus, $changedAt]) {
            $application->statusHistory()->create([
                'from_status' => $fromStatus,
                'status' => $toStatus,
                'changed_by' => $profile->user_id,
                'changed_at' => $changedAt,
            ]);
        }

        return $application->fresh(['jobPosting', 'statusHistory']);
    }

    private function databaseCounts(): array
    {
        return [
            'applications' => JobApplication::query()->count(),
            'postings' => JobPosting::query()->count(),
            'status_histories' => DB::table('job_application_status_histories')->count(),
            'tracking_histories' => DB::table('job_application_tracking_histories')->count(),
            'submission_confirmations' => DB::table('job_application_submission_confirmations')->count(),
            'scheduled_events' => DB::table('job_application_scheduled_events')->count(),
            'interactions' => DB::table('job_application_interactions')->count(),
        ];
    }
}
