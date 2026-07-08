<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildProfileApplicationPortfolioDashboard;
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

class BuildProfileApplicationPortfolioDashboardTest extends TestCase
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

    public function test_mixed_portfolio_combines_pipeline_integrity_follow_up_and_priorities(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$draft] = $this->applicationScenario($owner, $profile, 'draft');
        [$overdue] = $this->applicationScenario($owner, $profile, 'overdue');
        $this->confirm($overdue, $owner, 'overdue');
        app(TransitionJobApplicationStatus::class)->execute(
            $overdue,
            $owner,
            [
                'status' => 'screening',
                'changed_at' => '2026-07-02 09:00:00',
                'next_action_at' => '2026-07-07 09:00:00',
            ],
        );

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
                'changed_at' => '2026-07-08 12:00:00',
            ],
        );

        [$invalid, $invalidPath] = $this->applicationScenario(
            $owner,
            $profile,
            'invalid',
        );
        $this->confirm($invalid, $owner, 'invalid');
        Storage::disk('local')->put($invalidPath, 'tampered content');

        [$hired] = $this->applicationScenario($owner, $profile, 'hired');
        $this->confirm($hired, $owner, 'hired');
        app(TransitionJobApplicationStatus::class)->execute(
            $hired,
            $owner,
            [
                'status' => 'offer',
                'changed_at' => '2026-07-02 10:00:00',
            ],
        );
        app(TransitionJobApplicationStatus::class)->execute(
            $hired,
            $owner,
            [
                'status' => 'hired',
                'changed_at' => '2026-07-03 10:00:00',
            ],
        );

        $dashboard = app(BuildProfileApplicationPortfolioDashboard::class)->execute(
            $profile,
            $owner,
            [
                'reference_at' => '2026-07-08 12:00:00',
                'upcoming_days' => 7,
                'priority_limit' => 10,
            ],
        );

        $this->assertSame($profile->id, $dashboard['profile_id']);
        $this->assertSame('2026-07-08T12:00:00.000000Z', $dashboard['reference_at']);
        $this->assertSame(5, $dashboard['summary']['applications_total']);
        $this->assertSame(3, $dashboard['summary']['active_total']);
        $this->assertSame(2, $dashboard['summary']['terminal_total']);
        $this->assertSame(4, $dashboard['summary']['attention_total']);
        $this->assertSame(4, $dashboard['summary']['priority_returned']);
        $this->assertSame(4, $dashboard['summary']['submission_confirmed_total']);
        $this->assertSame(1, $dashboard['summary']['planned_events_total']);

        $this->assertSame(1, $dashboard['pipeline']['by_status']['draft']);
        $this->assertSame(1, $dashboard['pipeline']['by_status']['applied']);
        $this->assertSame(1, $dashboard['pipeline']['by_status']['screening']);
        $this->assertSame(1, $dashboard['pipeline']['by_status']['hired']);
        $this->assertSame(1, $dashboard['pipeline']['by_status']['rejected']);
        $this->assertSame(1, $dashboard['pipeline']['groups']['pre_submission']['total']);
        $this->assertSame(2, $dashboard['pipeline']['groups']['in_progress']['total']);
        $this->assertSame(2, $dashboard['pipeline']['groups']['outcomes']['total']);
        $this->assertSame(0, $dashboard['pipeline']['groups']['unknown']['total']);

        $this->assertSame([
            'healthy' => 3,
            'warning' => 1,
            'invalid' => 1,
        ], $dashboard['integrity']['by_status']);
        $this->assertSame(1, $dashboard['integrity']['errors_total']);
        $this->assertSame(1, $dashboard['integrity']['warnings_total']);
        $this->assertSame(2, $dashboard['integrity']['issues_total']);

        $this->assertSame(3, $dashboard['follow_up']['active_total']);
        $this->assertSame(1, $dashboard['follow_up']['by_urgency']['overdue']);
        $this->assertSame(2, $dashboard['follow_up']['by_urgency']['unscheduled']);
        $this->assertSame(1, $dashboard['follow_up']['by_source']['next_action']);
        $this->assertSame(2, $dashboard['follow_up']['by_source']['none']);

        $this->assertSame(
            [$invalid->id, $warning->id, $overdue->id, $draft->id],
            array_column($dashboard['priority_queue'], 'application_id'),
        );
        $this->assertSame(
            [
                'integrity_invalid',
                'integrity_warning',
                'follow_up_overdue',
                'follow_up_unscheduled',
            ],
            array_column($dashboard['priority_queue'], 'primary_signal'),
        );
        $warningItem = collect($dashboard['priority_queue'])
            ->firstWhere('application_id', $warning->id);
        $this->assertContains(
            'terminal_application_has_planned_events',
            $warningItem['integrity_issue_codes'],
        );
        $this->assertNull($warningItem['follow_up_at']);
        $this->assertNull($warningItem['scheduled_event']);
        $this->assertSame('planned', $event->fresh()->status);
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    public function test_invalid_overdue_application_is_deduplicated_and_keeps_both_signals(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$application, $path] = $this->applicationScenario(
            $owner,
            $profile,
            'invalid-overdue',
        );
        $this->confirm($application, $owner, 'invalid-overdue');
        app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'screening',
                'changed_at' => '2026-07-02 09:00:00',
                'next_action_at' => '2026-07-07 09:00:00',
            ],
        );
        Storage::disk('local')->put($path, 'tampered content');

        $dashboard = app(BuildProfileApplicationPortfolioDashboard::class)->execute(
            $profile,
            $owner,
            ['reference_at' => '2026-07-08 12:00:00'],
        );
        $item = $dashboard['priority_queue'][0];

        $this->assertSame(1, $dashboard['summary']['attention_total']);
        $this->assertSame(1, $dashboard['summary']['priority_returned']);
        $this->assertCount(1, $dashboard['priority_queue']);
        $this->assertSame($application->id, $item['application_id']);
        $this->assertSame('integrity_invalid', $item['primary_signal']);
        $this->assertSame(
            ['integrity_invalid', 'follow_up_overdue'],
            $item['signals'],
        );
        $this->assertSame('invalid', $item['integrity_status']);
        $this->assertSame('overdue', $item['follow_up_urgency']);
        $this->assertContains(
            'submitted_document_file_invalid',
            $item['integrity_issue_codes'],
        );
    }

    public function test_priority_limit_does_not_change_attention_totals(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$first] = $this->applicationScenario($owner, $profile, 'first');
        [$second] = $this->applicationScenario($owner, $profile, 'second');
        [$third] = $this->applicationScenario($owner, $profile, 'third');

        $dashboard = app(BuildProfileApplicationPortfolioDashboard::class)->execute(
            $profile,
            $owner,
            ['priority_limit' => 2],
        );

        $this->assertSame(3, $dashboard['summary']['applications_total']);
        $this->assertSame(3, $dashboard['summary']['attention_total']);
        $this->assertSame(2, $dashboard['summary']['priority_returned']);
        $this->assertSame([$first->id, $second->id], array_column(
            $dashboard['priority_queue'],
            'application_id',
        ));
        $this->assertNotContains(
            $third->id,
            array_column($dashboard['priority_queue'], 'application_id'),
        );
    }

    public function test_unknown_status_is_visible_in_pipeline_and_priority_queue(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_title' => 'Legacy role',
            'company_name' => 'Legacy company',
            'status' => 'legacy_unknown',
        ]);

        $dashboard = app(BuildProfileApplicationPortfolioDashboard::class)->execute(
            $profile,
            $owner,
        );
        $item = $dashboard['priority_queue'][0];

        $this->assertSame(1, $dashboard['pipeline']['by_status']['legacy_unknown']);
        $this->assertSame(1, $dashboard['pipeline']['groups']['unknown']['total']);
        $this->assertSame(
            ['legacy_unknown' => 1],
            $dashboard['pipeline']['groups']['unknown']['statuses'],
        );
        $this->assertSame(1, $dashboard['integrity']['by_status']['invalid']);
        $this->assertSame($application->id, $item['application_id']);
        $this->assertSame('integrity_invalid', $item['primary_signal']);
        $this->assertContains(
            'unsupported_application_status',
            $item['integrity_issue_codes'],
        );
    }

    public function test_empty_profile_returns_stable_dashboard(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();

        $dashboard = app(BuildProfileApplicationPortfolioDashboard::class)->execute(
            $profile,
            $owner,
        );

        $this->assertSame(0, $dashboard['summary']['applications_total']);
        $this->assertSame(0, $dashboard['summary']['active_total']);
        $this->assertSame(0, $dashboard['summary']['terminal_total']);
        $this->assertSame(0, $dashboard['summary']['attention_total']);
        $this->assertSame(0, $dashboard['integrity']['issues_total']);
        $this->assertSame(0, $dashboard['follow_up']['active_total']);
        $this->assertSame([], $dashboard['priority_queue']);
    }

    public function test_options_are_strictly_validated(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        $action = app(BuildProfileApplicationPortfolioDashboard::class);
        $invalidInputs = [
            ['unknown' => true],
            ['reference_at' => 'not-a-date'],
            ['upcoming_days' => 0],
            ['upcoming_days' => 31],
            ['priority_limit' => 0],
            ['priority_limit' => 101],
        ];

        foreach ($invalidInputs as $input) {
            try {
                $action->execute($profile, $owner, $input);

                $this->fail('Invalid dashboard options were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_dashboard_is_deterministic_and_read_only(): void
    {
        [$owner, $profile] = $this->ownerAndProfile();
        [$application] = $this->applicationScenario(
            $owner,
            $profile,
            'deterministic',
        );
        $before = $this->databaseCounts();
        $action = app(BuildProfileApplicationPortfolioDashboard::class);

        $first = $action->execute($profile, $owner);
        $second = $action->execute($profile, $owner);

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->databaseCounts());
        $this->assertSame('draft', $application->fresh()->status);
    }

    public function test_outsider_cannot_build_portfolio_dashboard(): void
    {
        [, $profile] = $this->ownerAndProfile();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(BuildProfileApplicationPortfolioDashboard::class)->execute(
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
        CarbonImmutable::setTestNow('2026-07-01 08:00:00');
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
            'reviewed_at' => '2026-07-01 07:30:00',
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
                'submitted_at' => '2026-07-01 09:00:00',
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
