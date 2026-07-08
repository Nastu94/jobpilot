<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationHealthReport;
use App\Models\JobApplication;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildProfileApplicationHealthReportUnknownStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_report_includes_unknown_application_statuses(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Unknown workflow role',
            'company_name' => 'Acme',
            'status' => 'legacy_unknown',
        ]);

        $report = app(BuildProfileApplicationHealthReport::class)->execute(
            $profile,
            $owner,
        );
        $codes = $report['applications'][0]['issue_codes'];

        $this->assertSame(1, $report['summary']['applications_total']);
        $this->assertSame(1, $report['summary']['matching_total']);
        $this->assertSame(1, $report['summary']['returned_total']);
        $this->assertSame(1, $report['summary']['invalid_total']);
        $this->assertSame(1, $report['summary']['by_application_status']['legacy_unknown']);
        $this->assertContains(
            'legacy_unknown',
            $report['filters']['application_statuses'],
        );
        $this->assertSame(
            $application->id,
            $report['applications'][0]['application_id'],
        );
        $this->assertSame(
            'invalid',
            $report['applications'][0]['integrity_status'],
        );
        $this->assertContains('unsupported_application_status', $codes);
        $this->assertContains('non_draft_application_missing_status_history', $codes);
    }
}
