<?php

namespace Tests\Feature\Foundation;

use App\Actions\Documents\ExportApprovedGeneratedDocumentVersion;
use App\Actions\Documents\ReviewGeneratedDocumentVersion;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\Profile;
use App\Models\User;
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExportApprovedGeneratedDocumentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_export_exact_approved_content_to_private_storage(): void
    {
        Storage::fake('local');
        [$owner, , $version, $content] = $this->approvedScenario();

        $exported = app(ExportApprovedGeneratedDocumentVersion::class)->execute(
            $version,
            $owner,
        );

        Storage::disk('local')->assertExists($exported->storage_path);
        $this->assertSame($content, Storage::disk('local')->get($exported->storage_path));
        $this->assertSame('local', $exported->storage_disk);
        $this->assertSame('targeted-cv-v1.md', $exported->filename);
        $this->assertSame('text/markdown', $exported->mime_type);
        $this->assertSame(strlen($content), $exported->file_size);
        $this->assertSame(hash('sha256', $content), $exported->checksum_sha256);
        $this->assertSame($exported->reviewed_content_sha256, $exported->checksum_sha256);
    }

    public function test_repeated_export_is_idempotent_and_repairs_tampered_stored_file(): void
    {
        Storage::fake('local');
        [$owner, , $version, $content] = $this->approvedScenario();
        $action = app(ExportApprovedGeneratedDocumentVersion::class);

        $first = $action->execute($version, $owner);
        Storage::disk('local')->put($first->storage_path, 'tampered file');
        $second = $action->execute($first, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->storage_path, $second->storage_path);
        $this->assertSame($content, Storage::disk('local')->get($second->storage_path));
        $this->assertDatabaseCount('generated_document_versions', 1);
    }

    public function test_unapproved_version_cannot_be_exported(): void
    {
        Storage::fake('local');
        [$owner, , $version] = $this->pendingScenario();

        try {
            app(ExportApprovedGeneratedDocumentVersion::class)->execute($version, $owner);

            $this->fail('A pending document version was exported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document_version', $exception->errors());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_content_changed_after_approval_cannot_be_exported(): void
    {
        Storage::fake('local');
        [$owner, , $version] = $this->approvedScenario();
        $version->forceFill(['content' => 'Modified after approval.'])->save();

        try {
            app(ExportApprovedGeneratedDocumentVersion::class)->execute($version, $owner);

            $this->fail('Modified post-approval content was exported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content', $exception->errors());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_technical_matching_review_draft_cannot_be_exported_directly(): void
    {
        Storage::fake('local');
        [$owner, , $version] = $this->approvedScenario();
        $version->forceFill([
            'generator_key' => DeterministicTargetedResumeDraftBuilder::KEY,
        ])->save();

        try {
            app(ExportApprovedGeneratedDocumentVersion::class)->execute($version, $owner);

            $this->fail('A technical matching review draft was exported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document_version', $exception->errors());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_user_cannot_export_another_users_document(): void
    {
        Storage::fake('local');
        [, , $version] = $this->approvedScenario();
        $outsider = User::factory()->create();

        try {
            app(ExportApprovedGeneratedDocumentVersion::class)->execute(
                $version,
                $outsider,
            );

            $this->fail('An outsider exported the document version.');
        } catch (AuthorizationException) {
            $this->assertSame([], Storage::disk('local')->allFiles());
            $this->assertNull($version->fresh()->storage_path);
        }
    }

    private function approvedScenario(): array
    {
        [$owner, $document, $version, $content] = $this->pendingScenario();
        $version = app(ReviewGeneratedDocumentVersion::class)->execute(
            $version,
            $owner,
            ['decision' => 'approved'],
        );

        return [$owner, $document, $version, $content];
    }

    private function pendingScenario(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'draft',
        ]);
        $content = "# Final targeted resume\n\nVerified experience.";
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'version_number' => 1,
            'generation_method' => 'manual',
            'generator_key' => 'manual_targeted_resume_finalization',
            'generator_version' => '1.0.0',
            'content_format' => 'markdown',
            'content' => $content,
            'review_status' => 'pending',
            'contains_unverified_claims' => false,
        ]);

        return [$owner, $document, $version, $content];
    }
}
