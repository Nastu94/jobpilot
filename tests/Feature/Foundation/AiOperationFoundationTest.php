<?php

namespace Tests\Feature\Foundation;

use App\Models\AiOperation;
use App\Models\GeneratedDocument;
use App\Models\GeneratedDocumentVersion;
use App\Models\JobPosting;
use App\Models\MatchAnalysis;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiOperationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_can_store_ordered_ai_operation_metadata_without_payload_columns(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'targeted_resume',
            'name' => 'Targeted CV',
        ]);
        $generatedVersion = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'match_analysis_id' => $analysis->id,
            'version_number' => 1,
        ]);

        $older = AiOperation::create([
            'profile_id' => $profile->id,
            'operation_type' => 'job_requirement_extraction',
            'provider' => 'example_provider',
            'model' => 'example-model-small',
            'status' => 'failed',
            'error_code' => 'timeout',
            'error_message' => 'The provider did not answer in time.',
            'started_at' => '2026-07-07 13:00:00',
            'completed_at' => '2026-07-07 13:00:05',
        ]);
        $newer = AiOperation::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'match_analysis_id' => $analysis->id,
            'generated_document_version_id' => $generatedVersion->id,
            'operation_type' => 'targeted_resume_generation',
            'provider' => 'example_provider',
            'model' => 'example-model-large',
            'prompt_template_key' => 'targeted_resume',
            'prompt_template_version' => '1.0.0',
            'status' => 'completed',
            'external_request_id' => 'request-123',
            'request_hash' => str_repeat('a', 64),
            'response_hash' => str_repeat('b', 64),
            'input_tokens' => 1250,
            'output_tokens' => 640,
            'duration_ms' => 4200,
            'cost_micros' => 18500,
            'cost_currency' => 'USD',
            'metadata' => [
                'temperature' => 0,
                'retry_count' => 0,
            ],
            'started_at' => '2026-07-07 14:00:00',
            'completed_at' => '2026-07-07 14:00:04',
        ]);

        $this->assertSame([$newer->id, $older->id], $profile->fresh()->aiOperations->pluck('id')->all());
        $this->assertTrue($newer->fresh()->jobPosting->is($posting));
        $this->assertTrue($newer->fresh()->matchAnalysis->is($analysis));
        $this->assertTrue($newer->fresh()->generatedDocumentVersion->is($generatedVersion));
        $this->assertSame(['temperature' => 0, 'retry_count' => 0], $newer->fresh()->metadata);
        $this->assertSame(1250, $newer->fresh()->input_tokens);
        $this->assertSame(18500, $newer->fresh()->cost_micros);
        $this->assertFalse($newer->fresh()->payloads_stored);
        $this->assertSame('2026-07-07 14:00:04', $newer->fresh()->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame($newer->id, $posting->fresh()->aiOperations->first()->id);
        $this->assertSame($newer->id, $analysis->fresh()->aiOperations->first()->id);
        $this->assertSame($newer->id, $generatedVersion->fresh()->aiOperations->first()->id);
        $this->assertFalse(Schema::hasColumn('ai_operations', 'request_payload'));
        $this->assertFalse(Schema::hasColumn('ai_operations', 'response_payload'));
    }

    public function test_deleting_linked_sources_preserves_ai_operation(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);
        $analysis = MatchAnalysis::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'ruleset_key' => 'deterministic_match',
            'ruleset_version' => '1.0.0',
        ]);
        $document = GeneratedDocument::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'document_type' => 'cover_letter',
            'name' => 'Cover letter',
        ]);
        $generatedVersion = GeneratedDocumentVersion::create([
            'generated_document_id' => $document->id,
            'match_analysis_id' => $analysis->id,
            'version_number' => 1,
        ]);
        $operation = AiOperation::create([
            'profile_id' => $profile->id,
            'job_posting_id' => $posting->id,
            'match_analysis_id' => $analysis->id,
            'generated_document_version_id' => $generatedVersion->id,
            'operation_type' => 'cover_letter_generation',
        ]);

        $generatedVersion->delete();
        $analysis->delete();
        $posting->delete();
        $operation->refresh();

        $this->assertNull($operation->generated_document_version_id);
        $this->assertNull($operation->match_analysis_id);
        $this->assertNull($operation->job_posting_id);
        $this->assertDatabaseHas('ai_operations', ['id' => $operation->id]);
    }

    public function test_deleting_profile_removes_ai_operations(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $operation = AiOperation::create([
            'profile_id' => $profile->id,
            'operation_type' => 'profile_summary_generation',
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('ai_operations', ['id' => $operation->id]);
    }
}
