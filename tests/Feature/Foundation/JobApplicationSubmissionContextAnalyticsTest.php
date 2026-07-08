<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationCompanyPerformance;
use App\Actions\Applications\BuildProfileApplicationLifecycleAnalytics;
use App\Actions\Applications\BuildProfileApplicationSourcePerformance;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobApplicationSubmissionContextAnalyticsTest extends TestCase
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

    public function test_lifecycle_analytics_accept_real_initial_draft_history(): void
    {
        [$owner, $profile] = $this->capturedApplication();

        $analytics = app(BuildProfileApplicationLifecycleAnalytics::class)->execute(
            $profile,
            $owner,
            ['reference_at' => '2026-07-31 12:00:00'],
        );

        $this->assertSame(1, $analytics['population']['submitted_total']);
        $this->assertSame(1, $analytics['population']['eligible_total']);
        $this->assertSame(0, $analytics['population']['excluded_total']);
        $this->assertSame([], $analytics['exclusions']);
        $this->assertSame(1, $analytics['transitions']['events_total']);
        $this->assertSame('draft', $analytics['transitions']['routes'][0]['from_status']);
        $this->assertSame('applied', $analytics['transitions']['routes'][0]['to_status']);
    }

    public function test_company_source_and_channel_analytics_prefer_submission_snapshot(): void
    {
        [$owner, $profile] = $this->capturedApplication();
        $range = [
            'reference_at' => '2026-07-31 12:00:00',
            'start_at' => '2026-07-01 00:00:00',
            'end_at' => '2026-07-31 12:00:00',
        ];

        $source = app(BuildProfileApplicationSourcePerformance::class)->execute(
            $profile,
            $owner,
            $range,
        );
        $channel = app(BuildProfileApplicationSourcePerformance::class)->execute(
            $profile,
            $owner,
            array_merge($range, ['dimension' => 'application_channel']),
        );
        $company = app(BuildProfileApplicationCompanyPerformance::class)->execute(
            $profile,
            $owner,
            array_merge($range, ['minimum_sample_size' => 1]),
        );

        $this->assertSame('linkedin', $source['groups'][0]['group_key']);
        $this->assertSame(
            'submission_snapshot_with_legacy_live_fallback',
            $source['methodology']['value_source'],
        );
        $this->assertSame('company website', $channel['groups'][0]['group_key']);
        $this->assertSame('acme', $company['companies'][0]['company_key']);
        $this->assertSame('Acme', $company['companies'][0]['display_name']);
        $this->assertSame(['Acme'], $company['companies'][0]['observed_names']);
        $this->assertSame(
            'submission_snapshot_with_legacy_live_fallback',
            $company['methodology']['value_source'],
        );
    }

    private function capturedApplication(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Lead Developer',
            'company_name' => 'Acme Updated',
            'source' => 'referral',
            'location' => 'Milan',
            'country_code' => 'IT',
            'remote_type' => 'remote',
            'employment_type' => 'contract',
            'seniority' => 'senior',
        ]);
        $appliedAt = CarbonImmutable::parse('2026-07-01 09:00:00');
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'job_title' => 'Lead Developer',
            'company_name' => 'Acme Updated',
            'status' => 'applied',
            'applied_at' => $appliedAt,
            'application_channel' => 'Recruiter',
            'external_reference' => 'APP-999',
        ]);
        $application->forceFill([
            'submitted_context_captured_at' => $appliedAt,
            'submitted_job_posting_id' => $posting->id,
            'submitted_job_title' => 'Backend Developer',
            'submitted_company_name' => 'Acme',
            'submitted_job_source' => 'linkedin',
            'submitted_job_location' => 'Rome',
            'submitted_job_country_code' => 'IT',
            'submitted_job_remote_type' => 'hybrid',
            'submitted_job_employment_type' => 'full_time',
            'submitted_job_seniority' => 'mid',
            'submitted_application_channel' => 'Company website',
            'submitted_external_reference' => 'APP-123',
        ])->save();
        $application->statusHistory()->create([
            'from_status' => null,
            'status' => 'draft',
            'changed_by' => $owner->id,
            'changed_at' => '2026-06-30 09:00:00',
        ]);
        $application->statusHistory()->create([
            'from_status' => 'draft',
            'status' => 'applied',
            'changed_by' => $owner->id,
            'changed_at' => $appliedAt,
        ]);

        return [$owner, $profile, $application, $posting];
    }
}
