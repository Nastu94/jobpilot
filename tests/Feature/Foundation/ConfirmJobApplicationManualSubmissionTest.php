<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\BuildJobApplicationTimeline;
use App\Actions\Applications\BuildJobApplicationWorkspace;
use App\Actions\Applications\ConfirmJobApplicationManualSubmission;
use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use App\Services\Applications\JobApplicationTimelineBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfirmJobApplicationManualSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-08 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_confirm_manual_submission_atomically(): void
    {
        [$owner, $application, $version] = $this->scenario();

        $confirmation = app(ConfirmJobApplicationManualSubmission::class)->execute(
            $application,
            $owner,
            $this->confirmationInput(),
        );
        $application = $application->fresh();

        $this->assertSame($application->id, $confirmation->job_application_id);
        $this->assertSame($owner->id, $confirmation->recorded_by);
        $this->assertSame('submission-001', $confirmation->client_reference);
        $this->assertSame('2026-07-08T11:00:00.000000Z', $confirmation->submitted_at->toISOString());
        $this->assertSame('company_website', $confirmation->application_channel);
        $this->assertSame('APP-900', $confirmation->external_reference);
        $this->assertSame(
            'https://jobs.example.com/backend/apply',
            $confirmation->destination_url,
        );
        $this->assertSame($version->id, $confirmation->generated_document_version_id);
        $this->assertSame($version->source_resume_version_id, $confirmation->source_resume_version_id);
        $this->assertSame(1, $confirmation->document_version_number);
        $this->assertSame('targeted-cv-v1.md', $confirmation->document_filename);
        $this->assertSame($version->checksum_sha256, $confirmation->document_checksum_sha256);
        $this->assertSame(
            "Submitted manually.\nConfirmation recorded.",
            $confirmation->notes,
        );

        $this->assertSame('applied', $application->status);
        $this->assertSame('2026-07-08T11:00:00.000000Z', $application->applied_at->toISOString());
        $this->assertSame('company_website', $application->application_channel);
        $this->assertSame('APP-900', $application->external_reference);
        $this->assertSame($version->id, $application->submitted_generated_document_version_id);
        $this->assertSame('targeted-cv-v1.md', $application->submitted_document_filename);
        $this->assertSame($version->checksum_sha256, $application->submitted_document_checksum_sha256);
        $this->assertDatabaseCount('job_application_submission_confirmations', 1);
        $this->assertDatabaseCount('job_application_status_histories', 1);
        $this->assertDatabaseCount('job_application_tracking_histories', 1);
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
        $this->assertDatabaseHas('job_application_status_histories', [
            'job_application_id' => $application->id,
            'from_status' => 'draft',
            'status' => 'applied',
            'notes' => 'Submitted manually. Confirmation recorded.',
        ]);
    }

    public function test_exact_replay_is_idempotent_after_application_progresses(): void
    {
        [$owner, $application] = $this->scenario();
        $input = $this->confirmationInput();
        $action = app(ConfirmJobApplicationManualSubmission::class);
        $first = $action->execute($application, $owner, $input);
        CarbonImmutable::setTestNow('2026-07-08 13:00:00');
        app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'screening',
                'changed_at' => '2026-07-08 12:30:00',
            ],
        );

        $second = $action->execute($application, $owner, $input);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('screening', $application->fresh()->status);
        $this->assertDatabaseCount('job_application_submission_confirmations', 1);
        $this->assertDatabaseCount('job_application_status_histories', 2);
        $this->assertDatabaseCount('job_application_tracking_histories', 1);
    }

    public function test_changed_replay_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(ConfirmJobApplicationManualSubmission::class);
        $action->execute($application, $owner, $this->confirmationInput());
        $changed = $this->confirmationInput();
        $changed['external_reference'] = 'APP-CHANGED';

        try {
            $action->execute($application, $owner, $changed);

            $this->fail('A different submission confirmation payload was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission_confirmation', $exception->errors());
            $this->assertDatabaseCount('job_application_submission_confirmations', 1);
            $this->assertDatabaseCount('job_application_status_histories', 1);
        }
    }

    public function test_non_draft_application_without_confirmation_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();
        app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => '2026-07-08 10:00:00',
            ],
        );

        try {
            app(ConfirmJobApplicationManualSubmission::class)->execute(
                $application,
                $owner,
                $this->confirmationInput(),
            );

            $this->fail('A non-draft application received its first confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('job_application', $exception->errors());
            $this->assertDatabaseCount('job_application_submission_confirmations', 0);
            $this->assertSame('applied', $application->fresh()->status);
        }
    }

    public function test_readiness_failure_rolls_back_confirmation_and_transition(): void
    {
        [$owner, $application, , $path] = $this->scenario();
        Storage::disk('local')->put($path, 'tampered content');

        try {
            app(ConfirmJobApplicationManualSubmission::class)->execute(
                $application,
                $owner,
                $this->confirmationInput(),
            );

            $this->fail('A tampered document was confirmed as submitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission_readiness', $exception->errors());
            $this->assertSame('draft', $application->fresh()->status);
            $this->assertDatabaseCount('job_application_submission_confirmations', 0);
            $this->assertDatabaseCount('job_application_status_histories', 0);
            $this->assertDatabaseCount('job_application_tracking_histories', 0);
        }
    }

    public function test_future_or_pre_creation_submission_time_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(ConfirmJobApplicationManualSubmission::class);
        $future = $this->confirmationInput();
        $future['submitted_at'] = '2026-07-08 13:00:00';

        try {
            $action->execute($application, $owner, $future);

            $this->fail('A future submission time was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submitted_at', $exception->errors());
        }

        $beforeCreation = $this->confirmationInput();
        $beforeCreation['submitted_at'] = '2026-07-08 07:00:00';

        try {
            $action->execute($application, $owner, $beforeCreation);

            $this->fail('A submission time before application creation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submitted_at', $exception->errors());
            $this->assertSame('draft', $application->fresh()->status);
            $this->assertDatabaseCount('job_application_submission_confirmations', 0);
        }
    }

    public function test_confirmation_input_is_strictly_validated(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(ConfirmJobApplicationManualSubmission::class);
        $invalidInputs = [];

        $unknown = $this->confirmationInput();
        $unknown['unknown'] = true;
        $invalidInputs[] = $unknown;

        $missingReference = $this->confirmationInput();
        unset($missingReference['client_reference']);
        $invalidInputs[] = $missingReference;

        $emptyChannel = $this->confirmationInput();
        $emptyChannel['application_channel'] = '   ';
        $invalidInputs[] = $emptyChannel;

        $invalidUrl = $this->confirmationInput();
        $invalidUrl['destination_url'] = 'ftp://jobs.example.com/apply';
        $invalidInputs[] = $invalidUrl;

        foreach ($invalidInputs as $input) {
            try {
                $action->execute($application, $owner, $input);

                $this->fail('Invalid confirmation input was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
                $this->assertSame('draft', $application->fresh()->status);
            }
        }

        $this->assertDatabaseCount('job_application_submission_confirmations', 0);
        $this->assertDatabaseCount('job_application_status_histories', 0);
    }

    public function test_outsider_cannot_confirm_another_users_submission(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(ConfirmJobApplicationManualSubmission::class)->execute(
            $application,
            $outsider,
            $this->confirmationInput(),
        );
    }

    public function test_application_delete_cascades_submission_confirmation(): void
    {
        [$owner, $application] = $this->scenario();
        app(ConfirmJobApplicationManualSubmission::class)->execute(
            $application,
            $owner,
            $this->confirmationInput(),
        );

        $application->delete();

        $this->assertDatabaseCount('job_application_submission_confirmations', 0);
    }

    public function test_confirmation_is_exposed_in_timeline_and_workspace(): void
    {
        [$owner, $application, $version] = $this->scenario();
        $confirmation = app(ConfirmJobApplicationManualSubmission::class)->execute(
            $application,
            $owner,
            $this->confirmationInput(),
        );

        $timeline = app(BuildJobApplicationTimeline::class)->execute(
            $application,
            $owner,
            ['direction' => 'asc'],
        );

        $this->assertSame(3, $timeline['summary']['available_total']);
        $this->assertSame(
            [
                JobApplicationTimelineBuilder::TYPE_STATUS_CHANGED,
                JobApplicationTimelineBuilder::TYPE_SUBMISSION_CONFIRMED,
                JobApplicationTimelineBuilder::TYPE_TRACKING_UPDATED,
            ],
            array_column($timeline['events'], 'event_type'),
        );
        $submissionEvent = $timeline['events'][1];
        $this->assertSame('submission_confirmed:'.$confirmation->id, $submissionEvent['event_key']);
        $this->assertSame($owner->id, $submissionEvent['actor']['id']);
        $this->assertSame('submission-001', $submissionEvent['details']['client_reference']);
        $this->assertSame($version->id, $submissionEvent['details']['generated_document_version_id']);
        $this->assertSame('targeted-cv-v1.md', $submissionEvent['details']['document_filename']);
        $this->assertArrayNotHasKey('storage_path', $submissionEvent['details']);
        $this->assertArrayNotHasKey('contents', $submissionEvent['details']);

        $workspace = app(BuildJobApplicationWorkspace::class)->execute(
            $application,
            $owner,
            ['reference_at' => '2026-07-08 12:00:00'],
        );

        $this->assertSame('applied', $workspace['application']['status']);
        $this->assertSame('submitted_snapshot', $workspace['document']['document_source']);
        $this->assertSame($confirmation->id, $workspace['submission_confirmation']['id']);
        $this->assertSame('submission-001', $workspace['submission_confirmation']['client_reference']);
        $this->assertSame($version->id, $workspace['submission_confirmation']['generated_document_version_id']);
        $this->assertSame($owner->id, $workspace['submission_confirmation']['recorded_by']['id']);
        $this->assertArrayNotHasKey('storage_path', $workspace['submission_confirmation']);
        $this->assertArrayNotHasKey('contents', $workspace['submission_confirmation']);
        $this->assertTrue($workspace['signals']['has_submission_confirmation']);
        $this->assertSame(1, $workspace['counts']['submission_confirmations_total']);
        $this->assertSame(3, $workspace['counts']['timeline_events_total']);
    }

    private function scenario(): array
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow('2026-07-08 08:00:00');

        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Acme',
            'source' => 'company_site',
            'external_id' => 'JOB-900',
            'source_url' => 'https://jobs.example.com/backend',
        ]);
        $resume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'Main CV',
        ]);
        $sourceVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'ready',
        ]);
        $content = '# Final targeted resume';
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
        $path = sprintf(
            'generated-documents/profile-%d/document-%d/version-%d/targeted-cv-v1.md',
            $profile->id,
            $document->id,
            $version->id,
        );
        Storage::disk('local')->put($path, $content);
        $version->forceFill([
            'storage_disk' => 'local',
            'storage_path' => $path,
            'filename' => 'targeted-cv-v1.md',
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

        return [
            $owner,
            $application,
            $version->fresh(),
            $path,
        ];
    }

    private function confirmationInput(): array
    {
        return [
            'client_reference' => '  submission-001  ',
            'submitted_at' => '2026-07-08 11:00:00',
            'application_channel' => ' company_website ',
            'external_reference' => ' APP-900 ',
            'destination_url' => ' https://jobs.example.com/backend/apply ',
            'notes' => "  Submitted manually.\nConfirmation recorded.  ",
        ];
    }
}
