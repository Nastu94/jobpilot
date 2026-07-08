<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\InspectJobApplicationSubmissionReadiness;
use App\Actions\Applications\SelectJobApplicationDocumentVersion;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
use App\Models\JobApplicationDocumentVersionHistory;
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

class SelectJobApplicationDocumentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_select_a_new_safe_version_and_source_resume_atomically(): void
    {
        [$owner, $application, $previousVersion, $newVersion] = $this->scenario();
        $changedAt = now()->subMinute()->startOfSecond();

        $updated = app(SelectJobApplicationDocumentVersion::class)->execute(
            $application,
            $newVersion,
            $owner,
            [
                'changed_at' => $changedAt->toDateTimeString(),
                'notes' => "  Selected after final review.\nReady to submit.  ",
            ],
        );

        $this->assertSame($newVersion->id, $updated->generated_document_version_id);
        $this->assertSame($newVersion->source_resume_version_id, $updated->resume_version_id);
        $this->assertTrue($updated->generatedDocumentVersion->is($newVersion));
        $this->assertTrue($updated->resumeVersion->is($newVersion->sourceResumeVersion));
        $this->assertCount(1, $updated->documentVersionHistory);

        $history = $updated->documentVersionHistory->first();
        $this->assertSame($owner->id, $history->changed_by);
        $this->assertTrue($history->changedBy->is($owner));
        $this->assertSame($newVersion->generated_document_id, $history->generated_document_id);
        $this->assertSame($previousVersion->id, $history->previous_generated_document_version_id);
        $this->assertSame($newVersion->id, $history->generated_document_version_id);
        $this->assertSame($previousVersion->source_resume_version_id, $history->previous_resume_version_id);
        $this->assertSame($newVersion->source_resume_version_id, $history->resume_version_id);
        $this->assertSame(1, $history->previous_version_number);
        $this->assertSame(2, $history->version_number);
        $this->assertSame($previousVersion->checksum_sha256, $history->previous_checksum_sha256);
        $this->assertSame($newVersion->checksum_sha256, $history->checksum_sha256);
        $this->assertSame($newVersion->reviewed_content_sha256, $history->reviewed_content_sha256);
        $this->assertSame($changedAt->toDateTimeString(), $history->changed_at->toDateTimeString());
        $this->assertSame("Selected after final review.\nReady to submit.", $history->notes);

        $readiness = app(InspectJobApplicationSubmissionReadiness::class)
            ->execute($updated, $owner);
        $this->assertTrue($readiness['ready']);
    }

    public function test_reselecting_the_current_version_is_idempotent(): void
    {
        [$owner, $application, , $newVersion] = $this->scenario();
        $action = app(SelectJobApplicationDocumentVersion::class);
        $updated = $action->execute(
            $application,
            $newVersion,
            $owner,
            ['changed_at' => now()->subHour()->toDateTimeString()],
        );

        $replayed = $action->execute(
            $updated,
            $newVersion,
            $owner,
            ['changed_at' => now()->subHours(2)->toDateTimeString()],
        );

        $this->assertSame($newVersion->id, $replayed->generated_document_version_id);
        $this->assertDatabaseCount('job_application_document_version_histories', 1);
    }

    public function test_only_draft_application_can_change_selected_version(): void
    {
        [$owner, $application, $previousVersion, $newVersion] = $this->scenario();
        $application->forceFill(['status' => 'applied'])->save();

        try {
            app(SelectJobApplicationDocumentVersion::class)->execute(
                $application,
                $newVersion,
                $owner,
            );

            $this->fail('A non-draft application changed its document version.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('job_application', $exception->errors());
            $this->assertSame($previousVersion->id, $application->fresh()->generated_document_version_id);
            $this->assertDatabaseCount('job_application_document_version_histories', 0);
        }
    }

    public function test_version_from_another_document_is_rejected(): void
    {
        [$owner, $application, $previousVersion, , , $profile, $posting, , $sourceVersion] = $this->scenario();
        $otherDocument = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'targeted_resume',
            'name' => 'Other targeted CV',
            'status' => 'ready',
        ]);
        $otherVersion = $this->version($otherDocument, $sourceVersion, 1, $owner);

        try {
            app(SelectJobApplicationDocumentVersion::class)->execute(
                $application,
                $otherVersion,
                $owner,
            );

            $this->fail('A version from another document was selected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document_version', $exception->errors());
            $this->assertSame($previousVersion->id, $application->fresh()->generated_document_version_id);
            $this->assertDatabaseCount('job_application_document_version_histories', 0);
        }
    }

    public function test_unapproved_or_unverified_version_is_rejected(): void
    {
        [$owner, $application, $previousVersion, $newVersion] = $this->scenario();
        $newVersion->forceFill([
            'review_status' => 'pending',
            'contains_unverified_claims' => true,
        ])->save();

        try {
            app(SelectJobApplicationDocumentVersion::class)->execute(
                $application,
                $newVersion,
                $owner,
            );

            $this->fail('An unsafe version was selected.');
        } catch (ValidationException $exception) {
            $messages = $exception->errors()['generated_document_version'] ?? [];
            $this->assertContains('The selected version is not approved.', $messages);
            $this->assertContains('The selected version contains unverified claims.', $messages);
            $this->assertSame($previousVersion->id, $application->fresh()->generated_document_version_id);
            $this->assertDatabaseCount('job_application_document_version_histories', 0);
        }
    }

    public function test_missing_or_tampered_private_export_is_rejected(): void
    {
        [$owner, $application, $previousVersion, $newVersion, , , , $newPath] = $this->scenario();
        Storage::disk('local')->put($newPath, 'tampered file');

        try {
            app(SelectJobApplicationDocumentVersion::class)->execute(
                $application,
                $newVersion,
                $owner,
            );

            $this->fail('A version with a tampered export was selected.');
        } catch (ValidationException $exception) {
            $messages = $exception->errors()['generated_document_version'] ?? [];
            $this->assertContains('The exported file no longer matches the approved content.', $messages);
            $this->assertSame($previousVersion->id, $application->fresh()->generated_document_version_id);
            $this->assertDatabaseCount('job_application_document_version_histories', 0);
        }
    }

    public function test_user_cannot_change_another_users_application(): void
    {
        [, $application, , $newVersion] = $this->scenario();
        $outsider = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(SelectJobApplicationDocumentVersion::class)->execute(
            $application,
            $newVersion,
            $outsider,
        );
    }

    public function test_selection_cannot_precede_latest_selection_history(): void
    {
        [$owner, $application, $previousVersion, $newVersion] = $this->scenario();
        $action = app(SelectJobApplicationDocumentVersion::class);
        $selected = $action->execute(
            $application,
            $newVersion,
            $owner,
            ['changed_at' => now()->subHour()->toDateTimeString()],
        );

        try {
            $action->execute(
                $selected,
                $previousVersion,
                $owner,
                ['changed_at' => now()->subHours(2)->toDateTimeString()],
            );

            $this->fail('A backdated document selection was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame($newVersion->id, $selected->fresh()->generated_document_version_id);
            $this->assertDatabaseCount('job_application_document_version_histories', 1);
        }
    }

    public function test_future_selection_is_rejected(): void
    {
        [$owner, $application, $previousVersion, $newVersion] = $this->scenario();

        try {
            app(SelectJobApplicationDocumentVersion::class)->execute(
                $application,
                $newVersion,
                $owner,
                ['changed_at' => now()->addHour()->toDateTimeString()],
            );

            $this->fail('A future document selection was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changed_at', $exception->errors());
            $this->assertSame($previousVersion->id, $application->fresh()->generated_document_version_id);
            $this->assertDatabaseCount('job_application_document_version_histories', 0);
        }
    }

    public function test_selection_history_survives_version_deletion_and_cascades_with_application(): void
    {
        [$owner, $application, $previousVersion, $newVersion] = $this->scenario();
        $selected = app(SelectJobApplicationDocumentVersion::class)->execute(
            $application,
            $newVersion,
            $owner,
        );
        $history = $selected->documentVersionHistory->first();
        $previousId = $previousVersion->id;
        $newId = $newVersion->id;
        $checksum = $history->checksum_sha256;

        $previousVersion->delete();
        $newVersion->delete();
        $preserved = JobApplicationDocumentVersionHistory::query()->firstOrFail();

        $this->assertNull($selected->fresh()->generated_document_version_id);
        $this->assertSame($previousId, $preserved->previous_generated_document_version_id);
        $this->assertSame($newId, $preserved->generated_document_version_id);
        $this->assertSame($checksum, $preserved->checksum_sha256);

        $selected->delete();

        $this->assertDatabaseCount('job_application_document_version_histories', 0);
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
        $sourceVersionOne = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv-v1.pdf',
            'storage_path' => 'resumes/cv-v1.pdf',
        ]);
        $sourceVersionTwo = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 2,
            'original_filename' => 'cv-v2.pdf',
            'storage_path' => 'resumes/cv-v2.pdf',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'ready',
        ]);
        $previousVersion = $this->version($document, $sourceVersionOne, 1, $owner);
        $newVersion = $this->version($document, $sourceVersionTwo, 2, $owner);
        $application = JobApplication::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $sourceVersionOne->id,
            'generated_document_version_id' => $previousVersion->id,
            'job_title' => $posting->title,
            'company_name' => $posting->company_name,
            'status' => 'draft',
        ]);
        $document->forceFill(['job_application_id' => $application->id])->save();

        return [
            $owner,
            $application,
            $previousVersion,
            $newVersion,
            $document,
            $profile,
            $posting,
            $newVersion->storage_path,
            $sourceVersionOne,
            $sourceVersionTwo,
        ];
    }

    private function version(
        GeneratedDocument $document,
        ResumeVersion $sourceVersion,
        int $versionNumber,
        User $owner,
    ): GeneratedDocumentVersion {
        $content = '# Final targeted resume v'.$versionNumber;
        $checksum = hash('sha256', $content);
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'source_resume_version_id' => $sourceVersion->id,
            'version_number' => $versionNumber,
            'generation_method' => 'manual',
            'generator_key' => 'manual_targeted_resume_finalization',
            'generator_version' => '1.0.0',
            'content_format' => 'markdown',
            'content' => $content,
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
            'reviewed_by' => $owner->id,
            'reviewed_at' => now()->subHour()->startOfSecond(),
            'reviewed_content_sha256' => $checksum,
        ]);
        $path = sprintf(
            'generated-documents/profile-%d/document-%d/version-%d/targeted-cv-v%d.md',
            $document->profile_id,
            $document->id,
            $version->id,
            $versionNumber,
        );
        Storage::disk('local')->put($path, $content);
        $version->forceFill([
            'storage_disk' => 'local',
            'storage_path' => $path,
            'filename' => 'targeted-cv-v'.$versionNumber.'.md',
            'mime_type' => 'text/markdown',
            'file_size' => strlen($content),
            'checksum_sha256' => $checksum,
        ])->save();

        return $version->fresh(['sourceResumeVersion.resume', 'generatedDocument']);
    }
}
