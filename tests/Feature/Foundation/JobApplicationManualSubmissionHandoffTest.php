<?php

namespace Tests\Feature\Foundation;

use App\Actions\Applications\InspectJobApplicationManualSubmissionHandoff;
use App\Actions\Applications\PrepareJobApplicationManualSubmissionHandoff;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobApplication;
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

class JobApplicationManualSubmissionHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_handoff_inspection_is_deterministic_and_has_no_side_effects(): void
    {
        [$owner, $application, $version] = $this->scenario();
        $action = app(InspectJobApplicationManualSubmissionHandoff::class);

        $first = $action->execute($application, $owner);
        $second = $action->execute($application, $owner);

        $this->assertSame($first, $second);
        $this->assertTrue($first['submission_readiness']['ready']);
        $this->assertSame('ready', $first['submission_readiness']['status']);
        $this->assertSame([], $first['submission_readiness']['blockers']);
        $this->assertSame($application->id, $first['application']['id']);
        $this->assertSame($version->id, $first['application']['generated_document_version_id']);
        $this->assertSame('Backend Developer', $first['application']['job_title']);
        $this->assertSame('Acme', $first['application']['company_name']);
        $this->assertSame('draft', $first['application']['status']);
        $this->assertSame('company_website', $first['application']['application_channel']);
        $this->assertSame('APP-001', $first['application']['external_reference']);
        $this->assertSame('company_site', $first['destination']['source']);
        $this->assertSame('https://jobs.example.com/backend', $first['destination']['source_url']);
        $this->assertSame('JOB-001', $first['destination']['external_id']);
        $this->assertSame([], $first['warnings']);
        $this->assertSame([
            'verify_submission_destination',
            'verify_application_details',
            'upload_approved_targeted_resume',
            'complete_external_form_without_inventing_facts',
            'review_external_submission',
            'submit_application_manually',
            'record_submission_in_jobpilot',
        ], $first['manual_steps']);
        $this->assertArrayNotHasKey('document', $first);
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
        $this->assertDatabaseCount('job_application_status_histories', 0);
        $this->assertDatabaseCount('job_application_tracking_histories', 0);
        $this->assertSame('draft', $application->fresh()->status);
    }

    public function test_missing_destination_metadata_creates_warnings_without_blocking_readiness(): void
    {
        [$owner, $application] = $this->scenario([
            'posting' => [
                'source' => null,
                'source_url' => null,
                'external_id' => null,
            ],
            'application' => [
                'application_channel' => null,
                'external_reference' => null,
            ],
        ]);

        $handoff = app(InspectJobApplicationManualSubmissionHandoff::class)->execute(
            $application,
            $owner,
        );

        $this->assertTrue($handoff['submission_readiness']['ready']);
        $this->assertSame([
            'source_url_missing',
            'application_channel_missing',
            'external_reference_missing',
        ], array_column($handoff['warnings'], 'code'));
        $this->assertNull($handoff['destination']['source_url']);
        $this->assertNull($handoff['destination']['application_channel']);
        $this->assertNull($handoff['destination']['external_reference']);
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    public function test_non_http_source_url_is_reported_as_warning_only(): void
    {
        [$owner, $application] = $this->scenario([
            'posting' => [
                'source_url' => 'ftp://jobs.example.com/backend',
            ],
        ]);

        $handoff = app(InspectJobApplicationManualSubmissionHandoff::class)->execute(
            $application,
            $owner,
        );

        $this->assertTrue($handoff['submission_readiness']['ready']);
        $this->assertSame(
            ['source_url_not_http'],
            array_column($handoff['warnings'], 'code'),
        );
    }

    public function test_blocked_inspection_returns_readiness_blockers_without_file_access(): void
    {
        [$owner, $application] = $this->scenario();
        $application->forceFill([
            'generated_document_version_id' => null,
        ])->save();

        $handoff = app(InspectJobApplicationManualSubmissionHandoff::class)->execute(
            $application,
            $owner,
        );

        $this->assertFalse($handoff['submission_readiness']['ready']);
        $this->assertSame('blocked', $handoff['submission_readiness']['status']);
        $this->assertContains(
            'selected_version_missing',
            array_column($handoff['submission_readiness']['blockers'], 'code'),
        );
        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    public function test_ready_handoff_preparation_returns_verified_document_and_audits_access(): void
    {
        [$owner, $application, $version, $content, $path] = $this->scenario();

        $handoff = app(PrepareJobApplicationManualSubmissionHandoff::class)->execute(
            $application,
            $owner,
        );

        $this->assertTrue($handoff['submission_readiness']['ready']);
        $this->assertSame($application->id, $handoff['document']['application_id']);
        $this->assertSame('selected_version', $handoff['document']['document_source']);
        $this->assertSame($version->id, $handoff['document']['generated_document_version_id']);
        $this->assertSame($version->source_resume_version_id, $handoff['document']['source_resume_version_id']);
        $this->assertSame('targeted-cv-v1.md', $handoff['document']['filename']);
        $this->assertSame('text/markdown', $handoff['document']['mime_type']);
        $this->assertSame(strlen($content), $handoff['document']['file_size']);
        $this->assertSame(hash('sha256', $content), $handoff['document']['checksum_sha256']);
        $this->assertSame($content, $handoff['document']['contents']);
        $this->assertIsInt($handoff['document']['access_history_id']);
        $this->assertNotNull($handoff['document']['accessed_at']);
        $this->assertArrayNotHasKey('storage_disk', $handoff['document']);
        $this->assertArrayNotHasKey('storage_path', $handoff['document']);
        $this->assertDatabaseHas('job_application_document_access_histories', [
            'job_application_id' => $application->id,
            'accessed_by' => $owner->id,
            'document_source' => 'selected_version',
            'generated_document_version_id' => $version->id,
            'filename' => 'targeted-cv-v1.md',
            'storage_disk' => 'local',
            'storage_path' => $path,
        ]);
        $this->assertSame('draft', $application->fresh()->status);
        $this->assertDatabaseCount('job_application_status_histories', 0);
        $this->assertDatabaseCount('job_application_tracking_histories', 0);
    }

    public function test_blocked_handoff_cannot_be_prepared_and_does_not_create_access_audit(): void
    {
        [$owner, $application] = $this->scenario();
        $application->forceFill([
            'generated_document_version_id' => null,
        ])->save();

        try {
            app(PrepareJobApplicationManualSubmissionHandoff::class)->execute(
                $application,
                $owner,
            );

            $this->fail('A blocked application produced a manual submission handoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'manual_submission_handoff',
                $exception->errors(),
            );
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_non_draft_application_cannot_be_prepared_and_is_not_changed(): void
    {
        [$owner, $application] = $this->scenario();
        $application->forceFill(['status' => 'applied'])->save();

        try {
            app(PrepareJobApplicationManualSubmissionHandoff::class)->execute(
                $application,
                $owner,
            );

            $this->fail('A non-draft application produced a manual submission handoff.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'Only a draft application can be prepared for submission.',
                $exception->errors()['manual_submission_handoff'] ?? [],
            );
            $this->assertSame('applied', $application->fresh()->status);
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_tampered_export_cannot_be_prepared_and_does_not_create_access_audit(): void
    {
        [$owner, $application, , , $path] = $this->scenario();
        Storage::disk('local')->put($path, 'tampered content');

        try {
            app(PrepareJobApplicationManualSubmissionHandoff::class)->execute(
                $application,
                $owner,
            );

            $this->fail('A tampered export produced a manual submission handoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'manual_submission_handoff',
                $exception->errors(),
            );
            $this->assertDatabaseCount('job_application_document_access_histories', 0);
        }
    }

    public function test_outsider_cannot_inspect_or_prepare_manual_submission_handoff(): void
    {
        [, $application] = $this->scenario();
        $outsider = User::factory()->create();

        foreach ([
            InspectJobApplicationManualSubmissionHandoff::class,
            PrepareJobApplicationManualSubmissionHandoff::class,
        ] as $actionClass) {
            try {
                app($actionClass)->execute($application, $outsider);

                $this->fail('An outsider accessed a manual submission handoff.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseCount('job_application_document_access_histories', 0);
    }

    private function scenario(array $overrides = []): array
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $posting = JobPosting::create(array_merge([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'company_name' => 'Acme',
            'source' => 'company_site',
            'external_id' => 'JOB-001',
            'source_url' => 'https://jobs.example.com/backend',
        ], $overrides['posting'] ?? []));
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
            'reviewed_at' => now()->subHour()->startOfSecond(),
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
        $application = JobApplication::create(array_merge([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'resume_version_id' => $sourceVersion->id,
            'generated_document_version_id' => $version->id,
            'job_title' => $posting->title,
            'company_name' => $posting->company_name,
            'status' => 'draft',
            'application_channel' => 'company_website',
            'external_reference' => 'APP-001',
        ], $overrides['application'] ?? []));
        $document->forceFill(['job_application_id' => $application->id])->save();

        return [
            $owner,
            $application,
            $version->fresh(),
            $content,
            $path,
        ];
    }
}
