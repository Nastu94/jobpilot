<?php

namespace Tests\Feature\Foundation;

use App\Actions\Documents\ReviewGeneratedDocumentVersion;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReviewGeneratedDocumentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_approve_a_reviewable_version(): void
    {
        [$owner, $document, $version] = $this->scenario();

        $reviewed = app(ReviewGeneratedDocumentVersion::class)->execute(
            $version,
            $owner,
            [
                'decision' => 'approved',
                'review_notes' => '  Verified against the source CV.  ',
            ],
        );

        $this->assertSame('approved', $reviewed->review_status);
        $this->assertSame($owner->id, $reviewed->reviewed_by);
        $this->assertTrue($reviewed->reviewedBy->is($owner));
        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertSame('Verified against the source CV.', $reviewed->review_notes);
        $this->assertSame('ready', $document->fresh()->status);
    }

    public function test_owner_can_reject_a_version_with_a_reason(): void
    {
        [$owner, $document, $version] = $this->scenario();

        $reviewed = app(ReviewGeneratedDocumentVersion::class)->execute(
            $version,
            $owner,
            [
                'decision' => 'rejected',
                'review_notes' => 'The ordering needs manual revision.',
            ],
        );

        $this->assertSame('rejected', $reviewed->review_status);
        $this->assertSame('The ordering needs manual revision.', $reviewed->review_notes);
        $this->assertSame('draft', $document->fresh()->status);
    }

    public function test_rejection_requires_review_notes(): void
    {
        [$owner, , $version] = $this->scenario();

        try {
            app(ReviewGeneratedDocumentVersion::class)->execute(
                $version,
                $owner,
                ['decision' => 'rejected'],
            );

            $this->fail('A rejection without notes was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review.review_notes', $exception->errors());
            $this->assertSame('pending', $version->fresh()->review_status);
        }
    }

    public function test_user_cannot_review_another_users_document(): void
    {
        [, , $version] = $this->scenario();
        $outsider = User::factory()->create();

        try {
            app(ReviewGeneratedDocumentVersion::class)->execute(
                $version,
                $outsider,
                ['decision' => 'approved'],
            );

            $this->fail('An outsider was able to review the document version.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $version->fresh()->review_status);
            $this->assertNull($version->fresh()->reviewed_by);
        }
    }

    public function test_version_with_unverified_claims_cannot_be_approved(): void
    {
        [$owner, , $version] = $this->scenario([
            'contains_unverified_claims' => true,
        ]);

        try {
            app(ReviewGeneratedDocumentVersion::class)->execute(
                $version,
                $owner,
                ['decision' => 'approved'],
            );

            $this->fail('A version with unverified claims was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
            $this->assertSame('pending', $version->fresh()->review_status);
        }
    }

    public function test_empty_version_cannot_be_approved(): void
    {
        [$owner, , $version] = $this->scenario([
            'content' => null,
            'storage_path' => null,
        ]);

        try {
            app(ReviewGeneratedDocumentVersion::class)->execute(
                $version,
                $owner,
                ['decision' => 'approved'],
            );

            $this->fail('An empty version was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
            $this->assertSame('pending', $version->fresh()->review_status);
        }
    }

    public function test_reviewed_version_cannot_be_overwritten(): void
    {
        [$owner, , $version] = $this->scenario();
        $action = app(ReviewGeneratedDocumentVersion::class);
        $approved = $action->execute(
            $version,
            $owner,
            ['decision' => 'approved'],
        );

        try {
            $action->execute(
                $approved,
                $owner,
                [
                    'decision' => 'rejected',
                    'review_notes' => 'Changed my mind.',
                ],
            );

            $this->fail('A completed review was overwritten.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
            $this->assertSame('approved', $approved->fresh()->review_status);
            $this->assertSame('ready', $approved->generatedDocument->fresh()->status);
        }
    }

    private function scenario(array $versionOverrides = []): array
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
            'status' => 'draft',
        ]);
        $version = GeneratedDocumentVersion::create(array_merge([
            'generated_document_id' => $document->id,
            'version_number' => 1,
            'generation_method' => 'template',
            'content_format' => 'markdown',
            'content' => '# Reviewable draft',
            'review_status' => 'pending',
            'contains_unverified_claims' => false,
        ], $versionOverrides));

        return [$owner, $document, $version];
    }
}
