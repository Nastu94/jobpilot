<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationHealthReport;
use App\Actions\Applications\ConfirmJobApplicationManualSubmission;
use App\Actions\Applications\ScheduleJobApplicationEvent;
use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildProfileApplicationHealthReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        CarbonImmutable::setTestNow('2026-07-08 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_mixed_portfolio_is_aggregated_and_prioritized(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$draft] = $this->applicationScenario($owner, $profile, 'draft');
        [$applied] = $this->applicationScenario($owner, $profile, 'applied');
        $this->confirm($applied, $owner, 'applied');
        [$warning] = $this->applicationScenario($owner, $profile, 'warning');
        $this->confirm($warning, $owner, 'warning');
        $event = app(ScheduleJobApplicationEvent::class)->execute(
            $warning,
            $owner,
            [
                'client_reference' => 'warning-event',
                'event_type' => 'interview',
                'title' => 'Interview still planned',
                'starts_at' => '2026-07-15 10:00:00',
            ],
        );
        app(TransitionJobApplicationStatus::class)->execute(
            $warning,
            $owner,
            [
                'status' => 'rejected',
                'changed_at' => '2026-07-08 11:30:00',
            ],
        );
        [$invalid, $invalidPath] = $this->applicationScenario(
            $owner,
            $profile,
            'invalid',
        );
        $this->confirm($invalid, $owner, 'invalid');
        Storage::disk('local')->put($invalidPath, 'tampered content');

        $report = app(BuildProfileApplicationHealthReport::class)->execute(
            $profile,
            $owner,
        );
        $byCode = collect($report['issues_by_code'])->keyBy('code');

        $this->assertSame($profile->id, $report['profile_id']);
        $this->assertSame(4, $report['summary']['applications_total']);
        $this->assertSame(2, $report['summary']['attention_total']);
        $this->assertSame(2, $report['summary']['healthy_total']);
        $this->assertSame(1, $report['summary']['warning_total']);
        $this->assertSame(1, $report['summary']['invalid_total']);
        $this->assertSame(1, $report['summary']['errors_total']);
        $this->assertSame(1, $report['summary']['warnings_total']);
        $this->assertSame(2, $report['summary']['issues_total']);
        $this->assertSame(4, $report['summary']['matching_total']);
        $this->assertSame(4, $report['summary']['returned_total']);
        $this->assertSame([
            'healthy' => 2,
            'warning' => 1,
            'invalid' => 1,
        ], $report['summary']['by_integrity_status']);
        $this->assertSame(1, $report['summary']['by_application_status']['draft']);
        $this->assertSame(2, $report['summary']['by_application_status']['applied']);
        $this->assertSame(1, $report['summary']['by_application_status']['rejected']);

        $this->assertSame(
            [$invalid->id, $warning->id],
            array_slice(array_column(
                $report['applications'],
                'application_id',
            ), 0, 2),
        );
        $this->assertSame(
            ['invalid', 'warning'],
            array_slice(array_column(
                $report['applications'],
                'integrity_status',
            ), 0, 2),
        );
        $this->assertContains(
            $draft->id,
            array_column($report['applications'], 'application_id'),
        );
        $this->assertContains(
            $applied->id,
            array_column($report['applications'], 'application_id'),
        );
        $this->assertSame(
            [$invalid->id],
            $byCode['submitted_document_file_invalid']['application_ids'],
        );
        $this->assertSame(
            [$warning->id],
            $byCode['terminal_application_has_planned_events']['application_ids'],
        );
        $this->assertSame(
            [$event->id],
            collect($report['applications'])
                ->firstWhere('application_id', $warning->id)['issues'][0]['context']['scheduled_event_ids'],
        );
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    public function test_filters_and_limit_only_affect_returned_applications(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$healthy] = $this->applicationScenario($owner, $profile, 'healthy');
        [$warning] = $this->applicationScenario($owner, $profile, 'warning');
        $this->confirm($warning, $owner, 'warning');
        app(ScheduleJobApplicationEvent::class)->execute(
            $warning,
            $owner,
            [
                'client_reference' => 'warning-event',
                'event_type' => 'deadline',
                'title' => 'Pending deadline',
                'starts_at' => '2026-07-15 10:00:00',
            ],
        );
        app(TransitionJobApplicationStatus::class)->execute(
            $warning,
            $owner,
            [
                'status' => 'rejected',
                'changed_at' => '2026-07-08 11:30:00',
            ],
        );
        [$invalid, $invalidPath] = $this->applicationScenario(
            $owner,
            $profile,
            'invalid',
        );
        $this->confirm($invalid, $owner, 'invalid');
        Storage::disk('local')->put($invalidPath, 'tampered content');

        $report = app(BuildProfileApplicationHealthReport::class)->execute(
            $profile,
            $owner,
            [
                'integrity_statuses' => ['invalid', 'warning'],
                'application_statuses' => ['applied', 'rejected'],
                'limit' => 1,
            ],
        );

        $this->assertSame(3, $report['summary']['applications_total']);
        $this->assertSame(2, $report['summary']['attention_total']);
        $this->assertSame(2, $report['summary']['matching_total']);
        $this->assertSame(1, $report['summary']['returned_total']);
        $this->assertSame($invalid->id, $report['applications'][0]['application_id']);
        $this->assertSame('invalid', $report['applications'][0]['integrity_status']);
        $this->assertSame([
            'integrity_statuses' => ['invalid', 'warning'],
            'application_statuses' => ['applied', 'rejected'],
            'limit' => 1,
        ], $report['filters']);
        $this->assertSame(1, $report['summary']['healthy_total']);
        $this->assertSame($healthy->id, $healthy->fresh()->id);
    }

    public function test_empty_profile_returns_stable_zero_summary(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $report = app(BuildProfileApplicationHealthReport::class)->execute(
            $profile,
            $owner,
        );

        $this->assertSame(0, $report['summary']['applications_total']);
        $this->assertSame(0, $report['summary']['attention_total']);
        $this->assertSame(0, $report['summary']['matching_total']);
        $this->assertSame(0, $report['summary']['returned_total']);
        $this->assertSame([
            'healthy' => 0,
            'warning' => 0,
            'invalid' => 0,
        ], $report['summary']['by_integrity_status']);
        $this->assertSame([], $report['issues_by_code']);
        $this->assertSame([], $report['applications']);
    }

    public function test_repeated_issue_code_is_aggregated_across_applications(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$first, $firstPath] = $this->applicationScenario(
            $owner,
            $profile,
            'first-invalid',
        );
        [$second, $secondPath] = $this->applicationScenario(
            $owner,
            $profile,
            'second-invalid',
        );
        $this->confirm($first, $owner, 'first-invalid');
        $this->confirm($second, $owner, 'second-invalid');
        Storage::disk('local')->put($firstPath, 'tampered first');
        Storage::disk('local')->put($secondPath, 'tampered second');

        $report = app(BuildProfileApplicationHealthReport::class)->execute(
            $profile,
            $owner,
        );
        $issue = collect($report['issues_by_code'])
            ->firstWhere('code', 'submitted_document_file_invalid');

        $this->assertSame(2, $issue['total']);
        $this->assertSame('error', $issue['severity']);
        $this->assertSame(
            [$first->id, $second->id],
            $issue['application_ids'],
        );
        $this->assertSame(2, $report['summary']['invalid_total']);
        $this->assertSame(2, $report['summary']['errors_total']);
    }

    public function test_options_are_strictly_validated(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $action = app(BuildProfileApplicationHealthReport::class);
        $invalidInputs = [
            ['unknown' => true],
            ['integrity_statuses' => []],
            ['integrity_statuses' => ['broken']],
            ['integrity_statuses' => ['healthy', 'healthy']],
            ['application_statuses' => []],
            ['application_statuses' => ['unknown']],
            ['limit' => 0],
            ['limit' => 201],
        ];

        foreach ($invalidInputs as $input) {
            try {
                $action->execute($profile, $owner, $input);

                $this->fail('Invalid health report options were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_report_is_deterministic_and_read_only(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$application] = $this->applicationScenario(
            $owner,
            $profile,
            'deterministic',
        );
        $before = $this->databaseCounts();
        $action = app(BuildProfileApplicationHealthReport::class);

        $first = $action->execute($profile, $owner);
        $second = $action->execute($profile, $owner);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame('draft', $application->fresh()->status);
    }

    public function test_outsider_cannot_build_profile_health_report(): void
    {
        [, $profile] = $this->ownerAndProfile();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(BuildProfileApplicationHealthReport::class)->execute(
            $profile,
            $outsider,
        );
    }

    private function ownerAndProfile(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);

        return [$owner, $profile];
    }

    private function applicationScenario(
        User $owner,
        Profile $profile,
        string $suffix,
    ): array {
        CarbonImmutable::setTestNow('2026-07-08 08:00:00');
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer '.$suffix,
            'company_name' => 'Acme '.$suffix,
            'source' => 'company_site',
            'external_id' => 'JOB-'.$suffix,
            'source_url' => 'https://jobs.example.com/'.$suffix,
        ]);
        $resume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'CV '.$suffix,
        ]);
        $sourceVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv-'.$suffix.'.pdf',
            'storage_path' => 'resumes/cv-'.$suffix.'.pdf',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV '.$suffix,
            'status' => 'ready',
        ]);
        $content = '# Final targeted resume '.$suffix;
        $checksum = hash('sha256', $content);
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'source_resume_version_id' => $sourceVersion->id,
            'version_number' => 1,
            'generation_method' => 'manual',
            'generator_key' => 'manual_targeted_resume_finalization',
            'generator_version' => '1.0.0',
            'content_format' => 'markdown',
            'content' => $content,
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
            'reviewed_by' => $owner->id,
            'reviewed_at' => '2026-07-08 07:30:00',
            'reviewed_content_sha256' => $checksum,
        ]);
        $filename = 'targeted-cv-'.$suffix.'.md';
        $path = sprintf(
            'generated-documents/profile-%d/document-%d/version-%d/%s',
            $profile->id,
            $document->id,
            $version->id,
            $filename,
        );
        Storage::disk('local')->put($path, $content);
        $version->forceFill([
            'storage_disk' => 'local',
            'storage_path' => $path,
            'filename' => $filename,
            'mime_type' => 'text/markdown',
            'file_size' => strlen($content),
            'checksum_sha256' => $checksum,
        ])->save();
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $sourceVersion->id,
            'generated_document_version_id' => $version->id,
            'job_title' => $posting->title,
            'company_name' => $posting->company_name,
            'status' => 'draft',
        ]);
        $document->forceFill(['job_application_id' => $application->id])->save();
        CarbonImmutable::setTestNow('2026-07-08 12:00:00');

        return [$application, $path];
    }

    private function confirm(
        JobApplication $application,
        User $owner,
        string $suffix,
    ): void {
        app(ConfirmJobApplicationManualSubmission::class)->execute(
            $application,
            $owner,
            [
                'client_reference' => 'submission-'.$suffix,
                'submitted_at' => '2026-07-08 11:00:00',
                'application_channel' => 'company_website',
                'external_reference' => 'APP-'.$suffix,
                'destination_url' => 'https://jobs.example.com/'.$suffix.'/apply',
            ],
        );
    }

    private function databaseCounts(): array
    {
        return [
            'applications' => JobApplication::query()->count(),
            'status_histories' => DB::table('job_application_status_histories')->count(),
            'tracking_histories' => DB::table('job_application_tracking_histories')->count(),
            'document_access_histories' => DB::table('job_application_document_access_histories')->count(),
            'submission_confirmations' => DB::table('job_application_submission_confirmations')->count(),
            'scheduled_events' => DB::table('job_application_scheduled_events')->count(),
            'event_histories' => DB::table('job_application_scheduled_event_histories')->count(),
            'event_replacements' => DB::table('job_application_scheduled_event_replacements')->count(),
        ];
    }
}
