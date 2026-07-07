<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\TransitionJobApplicationStatus;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobApplicationStatusHistory;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransitionJobApplicationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_mark_draft_as_applied_with_submission_metadata(): void
    {
        [$owner, $application] = $this->scenario();
        $changedAt = now()->subHour()->startOfSecond();
        $nextActionAt = now()->addDay()->startOfSecond();

        $updated = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'applied',
                'changed_at' => $changedAt->toDateTimeString(),
                'application_channel' => '  Company website  ',
                'external_reference' => '  APP-123  ',
                'next_action_at' => $nextActionAt->toDateTimeString(),
                'notes' => '  Application submitted manually.  ',
            ],
        );

        $this->assertSame('applied', $updated->status);
        $this->assertSame($changedAt->toDateTimeString(), $updated->applied_at->toDateTimeString());
        $this->assertSame('Company website', $updated->application_channel);
        $this->assertSame('APP-123', $updated->external_reference);
        $this->assertSame($nextActionAt->toDateTimeString(), $updated->next_action_at->toDateTimeString());
        $this->assertCount(2, $updated->statusHistory);

        $history = $updated->statusHistory->last();
        $this->assertSame('draft', $history->from_status);
        $this->assertSame('applied', $history->status);
        $this->assertSame($owner->id, $history->changed_by);
        $this->assertTrue($history->changedBy->is($owner));
        $this->assertSame('Application submitted manually.', $history->notes);
    }

    public function test_active_pipeline_can_progress_and_terminal_status_clears_next_action(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(TransitionJobApplicationStatus::class);
        $nextActionAt = now()->addDay()->startOfSecond();

        $applied = $action->execute($application, $owner, [
            'status' => 'applied',
            'changed_at' => now()->subMinutes(90)->toDateTimeString(),
            'next_action_at' => $nextActionAt->toDateTimeString(),
        ]);
        $interview = $action->execute($applied, $owner, [
            'status' => 'interview',
            'changed_at' => now()->subMinutes(60)->toDateTimeString(),
        ]);
        $offer = $action->execute($interview, $owner, [
            'status' => 'offer',
            'changed_at' => now()->subMinutes(30)->toDateTimeString(),
        ]);
        $hired = $action->execute($offer, $owner, [
            'status' => 'hired',
            'changed_at' => now()->subMinutes(10)->toDateTimeString(),
            'notes' => 'Offer accepted.',
        ]);

        $this->assertSame($nextActionAt->toDateTimeString(), $interview->next_action_at->toDateTimeString());
        $this->assertSame('hired', $hired->status);
        $this->assertNull($hired->next_action_at);
        $this->assertSame(
            ['draft', 'applied', 'interview', 'offer', 'hired'],
            $hired->statusHistory->pluck('status')->all(),
        );
    }

    public function test_repeating_current_status_is_idempotent(): void
    {
        [$owner, $application] = $this->scenario();

        $updated = app(TransitionJobApplicationStatus::class)->execute(
            $application,
            $owner,
            [
                'status' => 'draft',
                'application_channel' => 'Ignored on no-op',
            ],
        );

        $this->assertSame($application->id, $updated->id);
        $this->assertSame('draft', $updated->status);
        $this->assertNull($updated->application_channel);
        $this->assertDatabaseCount('job_application_status_histories', 1);
    }

    public function test_invalid_stage_jump_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();

        try {
            app(TransitionJobApplicationStatus::class)->execute(
                $application,
                $owner,
                ['status' => 'interview'],
            );

            $this->fail('A draft application jumped directly to interview.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame('draft', $application->fresh()->status);
            $this->assertDatabaseCount('job_application_status_histories', 1);
        }
    }

    public function test_terminal_status_cannot_be_reopened(): void
    {
        [$owner, $application] = $this->scenario();
        $action = app(TransitionJobApplicationStatus::class);
        $withdrawn = $action->execute($application, $owner, [
            'status' => 'withdrawn',
            'changed_at' => now()->subHour()->toDateTimeString(),
            'notes' => 'Candidate withdrew the application.',
        ]);

        try {
            $action->execute($withdrawn, $owner, ['status' => 'applied']);

            $this->fail('A terminal application status was reopened.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
            $this->assertSame('withdrawn', $withdrawn->fresh()->status);
            $this->assertDatabaseCount('job_application_status_histories', 2);
        }
    }

    public function test_user_cannot_transition_another_users_application(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        try {
            app(TransitionJobApplicationStatus::class)->execute(
                $application,
                $outsider,
                ['status' => 'applied'],
            );

            $this->fail('An outsider changed the application status.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $application->fresh()->status);
            $this->assertDatabaseCount('job_application_status_histories', 1);
        }
    }

    public function test_transition_cannot_precede_latest_history_entry(): void
    {
        [$owner, $application, $initialHistory] = $this->scenario();

        try {
            app(TransitionJobApplicationStatus::class)->execute(
                $application,
                $owner,
                [
                    'status' => 'applied',
                    'changed_at' => $initialHistory->changed_at->subMinute()->toDateTimeString(),
                ],
            );

            $this->fail('A backdated transition was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame('draft', $application->fresh()->status);
        }
    }

    public function test_future_transition_is_rejected(): void
    {
        [$owner, $application] = $this->scenario();

        try {
            app(TransitionJobApplicationStatus::class)->execute(
                $application,
                $owner,
                [
                    'status' => 'applied',
                    'changed_at' => now()->addHour()->toDateTimeString(),
                ],
            );

            $this->fail('A future transition was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame('draft', $application->fresh()->status);
        }
    }

    public function test_next_action_cannot_precede_transition(): void
    {
        [$owner, $application] = $this->scenario();
        $changedAt = now()->subHour();

        try {
            app(TransitionJobApplicationStatus::class)->execute(
                $application,
                $owner,
                [
                    'status' => 'applied',
                    'changed_at' => $changedAt->toDateTimeString(),
                    'next_action_at' => $changedAt->subMinute()->toDateTimeString(),
                ],
            );

            $this->fail('An invalid next action date was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('next_action_at', $exception->errors());
            $this->assertNull($application->fresh()->next_action_at);
        }
    }

    public function test_draft_cannot_be_marked_applied_without_a_safe_selected_version(): void
    {
        [$owner, $application, , $version] = $this->scenario();
        $version->forceFill([
            'review_status' => 'pending',
            'contains_unverified_claims' => true,
        ])->save();

        try {
            app(TransitionJobApplicationStatus::class)->execute(
                $application,
                $owner,
                ['status' => 'applied'],
            );

            $this->fail('An unsafe selected version was used for an application.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission_readiness', $exception->errors());
            $this->assertSame('draft', $application->fresh()->status);
            $this->assertNull($application->fresh()->applied_at);
        }
    }

    private function scenario(): array
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Acme',
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
        $content = '# Approved targeted resume';
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
            'reviewed_at' => now()->subHours(2),
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
            'job_title' => 'Backend Developer',
            'company_name' => 'Acme',
            'status' => 'draft',
        ]);
        $document->forceFill(['job_application_id' => $application->id])->save();
        $initialHistory = JobApplicationStatusHistory::create([
            'job_application_id' => $application->id,
            'from_status' => null,
            'status' => 'draft',
            'changed_by' => $owner->id,
            'changed_at' => now()->subHours(2),
            'notes' => 'Draft created.',
        ]);

        return [$owner, $application, $initialHistory, $version];
    }
}
