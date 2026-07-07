<?php

namespace Tests\Feature\Foundation;

use App\Actions\Documents\ReviewGeneratedDocumentVersion;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewedDocumentContentHashTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_records_the_content_hash(): void
    {
        $owner = User::factory()->create();
        $profile = Profile::create(['user_id' => $owner->id]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
        ]);
        $content = 'Verified final content.';
        $version = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'version_number' => 1,
            'generation_method' => 'manual',
            'content_format' => 'plain_text',
            'content' => $content,
            'review_status' => 'pending',
            'contains_unverified_claims' => false,
        ]);

        $reviewed = app(ReviewGeneratedDocumentVersion::class)->execute(
            $version,
            $owner,
            ['decision' => 'approved'],
        );

        $this->assertSame(hash('sha256', $content), $reviewed->reviewed_content_sha256);
    }
}
