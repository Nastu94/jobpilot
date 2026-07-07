<?php

namespace Tests\Feature\Foundation;

use App\Actions\Documents\CreateFinalTargetedResumeVersion;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeVersion;
use App\Models\User;
use App\Services\Documents\DeterministicTargetedResumeDraftBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateFinalTargetedResumeVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_pending_final_version_from_a_review_draft(): void
    {
        [$owner, $document, $baseVersion, $sourceVersion] = $this->scenario();
        $content = "# Vittorio Soligo\n\nPHP Developer";

        $version = app(CreateFinalTargetedResumeVersion::class)->execute(
            $baseVersion,
            $owner,
            [
                'content' => $content,
                'content_format' => 'markdown',
                'change_summary' => 'Removed technical review notes and finalized the ordering.',
            ],
        );

        $this->assertSame(2, $version->version_number);
        $this->assertSame('manual', $version->generation_method);
        $this->assertSame(CreateFinalTargetedResumeVersion::KEY, $version->generator_key);
        $this->assertSame(CreateFinalTargetedResumeVersion::VERSION, $version->generator_version);
        $this->assertSame('pending', $version->review_status);
        $this->assertFalse($version->contains_unverified_claims);
        $this->assertSame($content, $version->content);
        $this->assertSame('markdown', $version->content_format);
        $this->assertSame($sourceVersion->id, $version->source_resume_version_id);
        $this->assertSame(64, strlen($version->input_hash));
        $this->assertSame('draft', $document->fresh()->status);
    }

    public function test_exact_finalization_is_idempotent_but_changed_content_creates_a_new_version(): void
    {
        [$owner, , $baseVersion] = $this->scenario();
        $action = app(CreateFinalTargetedResumeVersion::class);
        $input = [
            'content' => 'Final CV content.',
            'content_format' => 'plain_text',
            'change_summary' => 'Prepared final plain-text CV.',
        ];

        $first = $action->execute($baseVersion, $owner, $input);
        $retry = $action->execute($baseVersion, $owner, $input);
        $second = $action->execute($baseVersion, $owner, array_merge($input, [
            'content' => 'Updated final CV content.',
            'change_summary' => 'Updated the final wording.',
        ]));

        $this->assertSame($first->id, $retry->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $first->version_number);
        $this->assertSame(3, $second->version_number);
        $this->assertDatabaseCount('generated_document_versions', 3);
    }

    public function test_user_cannot_finalize_another_users_document(): void
    {
        [, , $baseVersion] = $this->scenario();
        $outsider = User::factory()->create();

        try {
            app(CreateFinalTargetedResumeVersion::class)->execute(
                $baseVersion,
                $outsider,
                $this->validInput(),
            );

            $this->fail('An outsider finalized the generated document.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('generated_document_versions', 1);
        }
    }

    public function test_only_targeted_resume_documents_can_be_finalized(): void
    {
        [$owner, $document, $baseVersion] = $this->scenario();
        $document->forceFill(['document_type' => 'cover_letter'])->save();

        try {
            app(CreateFinalTargetedResumeVersion::class)->execute(
                $baseVersion,
                $owner,
                $this->validInput(),
            );

            $this->fail('A non-targeted document was finalized as a resume.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generated_document', $exception->errors());
            $this->assertDatabaseCount('generated_document_versions', 1);
        }
    }

    public function test_final_version_requires_complete_supported_input(): void
    {
        [$owner, , $baseVersion] = $this->scenario();

        try {
            app(CreateFinalTargetedResumeVersion::class)->execute(
                $baseVersion,
                $owner,
                [
                    'content' => '',
                    'content_format' => 'docx',
                ],
            );

            $this->fail('Invalid final content was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('version.content', $exception->errors());
            $this->assertArrayHasKey('version.content_format', $exception->errors());
            $this->assertArrayHasKey('version.change_summary', $exception->errors());
            $this->assertDatabaseCount('generated_document_versions', 1);
        }
    }

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $resume = Resume::create([
            'profile_id' => $profile->id,
            'name' => 'Main CV',
        ]);
        $sourceVersion = ResumeVersion::create([
            'resume_id' => $resume->id,
            'version_number' => 1,
            'original_filename' => 'cv.pdf',
            'storage_path' => 'resumes/cv.pdf',
            'processing_status' => 'completed',
            'extracted_text' => 'Verified source CV.',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'ready',
        ]);
        $baseVersion = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'source_resume_version_id' => $sourceVersion->id,
            'version_number' => 1,
            'generation_method' => 'template',
            'generator_key' => DeterministicTargetedResumeDraftBuilder::KEY,
            'generator_version' => DeterministicTargetedResumeDraftBuilder::VERSION,
            'content_format' => 'markdown',
            'content' => "# Review draft\n\n## Matching review notes (not part of the final resume)",
            'review_status' => 'approved',
            'contains_unverified_claims' => false,
        ]);

        return [$owner, $document, $baseVersion, $sourceVersion];
    }

    private function validInput(): array
    {
        return [
            'content' => 'Final targeted resume.',
            'content_format' => 'plain_text',
            'change_summary' => 'Finalized manually.',
        ];
    }
}
