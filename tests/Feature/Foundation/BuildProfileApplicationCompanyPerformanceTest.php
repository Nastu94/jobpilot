<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationCompanyPerformance;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildProfileApplicationCompanyPerformanceTest extends TestCase
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

    public function test_company_names_are_normalized_with_variants_performance_and_exclusions(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->application(
            $profile,
            'acme-hired',
            'Acme',
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
            'acme-active',
            '  ACME  ',
            'screening',
            '2026-06-10 09:00:00',
            [
                ['draft', 'applied', '2026-06-10 09:00:00'],
                ['applied', 'screening', '2026-06-11 09:00:00'],
            ],
        );
        $invalid = $this->application(
            $profile,
            'acme-invalid',
            'acme',
            'applied',
            '2026-06-20 09:00:00',
        );
        $this->application(
            $profile,
            'beta-rejected',
            'Beta S.p.A.',
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
            '   ',
            'applied',
            '2026-07-10 09:00:00',
            [['draft', 'applied', '2026-07-10 09:00:00']],
        );

        $analytics = app(BuildProfileApplicationCompanyPerformance::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-07-31 12:00:00',
                'start_at' => '2026-06-01 00:00:00',
                'end_at' => '2026-07-31 12:00:00',
                'minimum_sample_size' => 2,
            ],
        );
        $companies = collect($analytics['companies'])->keyBy('company_key');

        $this->assertSame('descriptive_not_causal', $analytics['methodology']['interpretation']);
        $this->assertSame('normalized_application_company_name_snapshot', $analytics['methodology']['grouping']);
        $this->assertTrue($analytics['methodology']['insufficient_samples_are_returned_not_hidden']);
        $this->assertSame(2, $analytics['minimum_sample_size']);
        $this->assertSame([
            'submitted_in_range_total' => 5,
            'eligible_total' => 4,
            'excluded_total' => 1,
            'companies_total' => 3,
            'companies_meeting_minimum_sample' => 1,
        ], $analytics['population']);
        $this->assertSame(
            ['acme', 'beta s.p.a.', 'unknown'],
            array_column($analytics['companies'], 'company_key'),
        );

        $this->assertSame('ACME', $companies['acme']['display_name']);
        $this->assertSame(
            ['ACME', 'Acme', 'acme'],
            $companies['acme']['observed_names'],
        );
        $this->assertSame(3, $companies['acme']['submitted_in_range_total']);
        $this->assertSame(2, $companies['acme']['eligible_total']);
        $this->assertSame(1, $companies['acme']['excluded_total']);
        $this->assertTrue($companies['acme']['meets_minimum_sample']);
        $this->assertSame(
            [$invalid->id],
            $companies['acme']['exclusions'][0]['application_ids'],
        );
        $this->assertSame(2, $companies['acme']['performance']['submitted_total']);
        $this->assertSame(1, $companies['acme']['performance']['active_total']);
        $this->assertSame(1, $companies['acme']['performance']['terminal_total']);
        $this->assertSame(1, $companies['acme']['performance']['milestones']['interview']['reached_total']);
        $this->assertSame(50.0, $companies['acme']['performance']['milestones']['interview']['conversion_percent']);
        $this->assertSame(48.0, $companies['acme']['performance']['milestones']['interview']['time_from_application']['average_hours']);
        $this->assertSame(1, $companies['acme']['performance']['outcomes']['hired']['total']);
        $this->assertSame(50.0, $companies['acme']['performance']['outcomes']['hired']['rate_from_submitted_percent']);

        $this->assertFalse($companies['beta s.p.a.']['meets_minimum_sample']);
        $this->assertSame(1, $companies['beta s.p.a.']['performance']['outcomes']['rejected']['total']);
        $this->assertSame('Unknown company', $companies['unknown']['display_name']);
        $this->assertSame([], $companies['unknown']['observed_names']);
        $this->assertFalse($companies['unknown']['meets_minimum_sample']);
        $this->assertSame(1, $companies['unknown']['performance']['active_total']);
        $this->assertSame(4, $analytics['totals']['submitted_total']);
        $this->assertSame('missing_status_history', $analytics['exclusions'][0]['reason_code']);
    }

    public function test_minimum_sample_annotation_does_not_hide_small_companies(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->appliedApplication($profile, 'zeta', 'Zeta', '2026-07-01 09:00:00');
        $this->appliedApplication($profile, 'alpha', 'Alpha', '2026-07-02 09:00:00');

        $analytics = app(BuildProfileApplicationCompanyPerformance::class)->execute(
            $profile,
            $owner,
            [
                'start_at' => '2026-07-01 00:00:00',
                'end_at' => '2026-07-31 12:00:00',
                'minimum_sample_size' => 3,
            ],
        );

        $this->assertSame(2, $analytics['population']['companies_total']);
        $this->assertSame(0, $analytics['population']['companies_meeting_minimum_sample']);
        $this->assertSame(
            ['alpha', 'zeta'],
            array_column($analytics['companies'], 'company_key'),
        );
        $this->assertSame([false, false], array_column(
            $analytics['companies'],
            'meets_minimum_sample',
        ));
    }

    public function test_range_excludes_submissions_outside_boundaries(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $this->appliedApplication($profile, 'before', 'Acme', '2026-05-31 23:59:59');
        $inside = $this->appliedApplication($profile, 'inside', 'Acme', '2026-06-15 09:00:00');
        $this->appliedApplication($profile, 'after', 'Acme', '2026-07-01 00:00:01');

        $analytics = app(BuildProfileApplicationCompanyPerformance::class)->execute(
            $profile,
            $owner,
            [
                'start_at' => '2026-06-01 00:00:00',
                'end_at' => '2026-06-30 23:59:59',
            ],
        );

        $this->assertSame(1, $analytics['population']['submitted_in_range_total']);
        $this->assertSame(1, $analytics['population']['eligible_total']);
        $this->assertSame(1, $analytics['companies'][0]['submitted_in_range_total']);
        $this->assertSame('applied', $inside->fresh()->status);
    }

    public function test_empty_range_returns_stable_zero_performance(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $analytics = app(BuildProfileApplicationCompanyPerformance::class)->execute(
            $profile,
            $owner,
            [
                'start_at' => '2026-06-01 00:00:00',
                'end_at' => '2026-07-31 12:00:00',
            ],
        );

        $this->assertSame(0, $analytics['population']['submitted_in_range_total']);
        $this->assertSame(0, $analytics['population']['companies_total']);
        $this->assertSame(0, $analytics['population']['companies_meeting_minimum_sample']);
        $this->assertSame([], $analytics['companies']);
        $this->assertSame([], $analytics['exclusions']);
        $this->assertSame(0, $analytics['totals']['submitted_total']);
        $this->assertNull($analytics['totals']['milestones']['offer']['conversion_percent']);
    }

    public function test_default_range_covers_twelve_calendar_months(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $analytics = app(BuildProfileApplicationCompanyPerformance::class)->execute(
            $profile,
            $owner,
        );

        $this->assertSame('2025-08-01T00:00:00.000000Z', $analytics['range']['start_at']);
        $this->assertSame('2026-07-31T12:00:00.000000Z', $analytics['range']['end_at']);
        $this->assertSame(3, $analytics['minimum_sample_size']);
    }

    public function test_options_and_range_are_strictly_validated(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $action = app(BuildProfileApplicationCompanyPerformance::class);
        $invalidInputs = [
            ['unknown' => true],
            ['reference_at' => 'not-a-date'],
            ['reference_at' => '2026-08-01 12:00:00'],
            ['minimum_sample_size' => 0],
            ['minimum_sample_size' => 101],
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

                $this->fail('Invalid company performance options were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_company_performance_is_deterministic_and_read_only(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $application = $this->appliedApplication(
            $profile,
            'deterministic',
            'Acme',
            '2026-07-01 09:00:00',
        );
        $before = $this->databaseCounts();
        $action = app(BuildProfileApplicationCompanyPerformance::class);

        $first = $action->execute($profile, $owner);
        $second = $action->execute($profile, $owner);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame('applied', $application->fresh()->status);
    }

    public function test_outsider_cannot_build_company_performance(): void
    {
        [, $profile] = $this->ownerAndProfile();

        $this->expectException(AuthorizationException::class);

        app(BuildProfileApplicationCompanyPerformance::class)->execute(
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
        string $companyName,
        string $appliedAt,
    ): JobApplication {
        return $this->application(
            $profile,
            $suffix,
            $companyName,
            'applied',
            $appliedAt,
            [['draft', 'applied', $appliedAt]],
        );
    }

    private function application(
        Profile $profile,
        string $suffix,
        string $companyName,
        string $status,
        string $appliedAt,
        array $history = [],
    ): JobApplication {
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Role '.$suffix,
            'company_name' => $companyName,
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
