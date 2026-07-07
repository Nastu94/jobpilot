<?php

namespace Tests\Feature\Foundation;

use App\Actions\Ai\ExtractJobPostingRequirements;
use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\JobPostingRequirement;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiOperationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use UnexpectedValueException;

class ExtractJobPostingRequirementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_response_creates_pending_ai_requirements_with_exact_evidence(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
            'raw_content' => implode("\n", [
                'At least 3 years of PHP experience.',
                'English level B2 is preferred.',
            ]),
        ]);
        $response = json_encode([
            'requirements' => [
                [
                    'type' => 'skill',
                    'importance' => 'required',
                    'label' => 'PHP',
                    'normalized_label' => ' PHP ',
                    'min_years' => 3,
                    'confidence_bps' => 9400,
                    'evidence' => 'At least 3 years of PHP experience.',
                ],
                [
                    'type' => 'language',
                    'importance' => 'preferred',
                    'label' => 'English',
                    'proficiency_level' => 'B2',
                    'confidence_bps' => 9000,
                    'evidence' => 'English level B2 is preferred.',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $requirements = $this->actionReturning($response)->execute($posting);

        $this->assertCount(2, $requirements);
        $this->assertSame(['PHP', 'English'], $requirements->pluck('label')->all());
        $this->assertSame(['skill', 'language'], $requirements->pluck('requirement_type')->all());
        $this->assertSame(['required', 'preferred'], $requirements->pluck('importance')->all());
        $this->assertSame(['pending', 'pending'], $requirements->pluck('review_status')->all());
        $this->assertSame(['ai', 'ai'], $requirements->pluck('source')->all());
        $this->assertSame('php', $requirements[0]->normalized_label);
        $this->assertSame('3.0', $requirements[0]->min_years);
        $this->assertSame(9400, $requirements[0]->confidence_bps);
        $this->assertSame('B2', $requirements[1]->proficiency_level);
        $this->assertNull($requirements[0]->skill_id);
        $this->assertNull($requirements[1]->language_id);

        $this->assertSame('skill', $requirements[0]->proposed_requirement_type);
        $this->assertSame('required', $requirements[0]->proposed_importance);
        $this->assertSame('PHP', $requirements[0]->proposed_label);
        $this->assertSame('php', $requirements[0]->proposed_normalized_label);
        $this->assertSame('3.0', $requirements[0]->proposed_min_years);
        $this->assertSame('language', $requirements[1]->proposed_requirement_type);
        $this->assertSame('B2', $requirements[1]->proposed_proficiency_level);

        $operation = AiOperation::query()->sole();

        $this->assertSame('completed', $operation->status);
        $this->assertSame('job_requirement_extraction', $operation->operation_type);
        $this->assertSame('job_requirement_extraction', $operation->prompt_template_key);
        $this->assertSame('1.0.0', $operation->prompt_template_version);
        $this->assertFalse($operation->payloads_stored);
        $this->assertSame(0, $operation->cost_micros);
    }

    public function test_rerun_replaces_only_pending_ai_requirements(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'description' => 'Strong PHP knowledge is required.',
        ]);
        $pending = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'skill',
            'label' => 'Old pending AI suggestion',
            'source' => 'ai',
            'review_status' => 'pending',
        ]);
        $approved = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'skill',
            'label' => 'Approved AI suggestion',
            'source' => 'ai',
            'review_status' => 'approved',
        ]);
        $manual = JobPostingRequirement::create([
            'job_posting_id' => $posting->id,
            'requirement_type' => 'skill',
            'label' => 'Manual requirement',
            'source' => 'manual',
            'review_status' => 'approved',
        ]);
        $response = json_encode([
            'requirements' => [[
                'type' => 'skill',
                'importance' => 'required',
                'label' => 'PHP',
                'evidence' => 'Strong PHP knowledge is required.',
            ]],
        ], JSON_THROW_ON_ERROR);

        $requirements = $this->actionReturning($response)->execute($posting);

        $this->assertCount(1, $requirements);
        $this->assertSame('PHP', $requirements->first()->label);
        $this->assertDatabaseMissing('job_posting_requirements', ['id' => $pending->id]);
        $this->assertDatabaseHas('job_posting_requirements', ['id' => $approved->id]);
        $this->assertDatabaseHas('job_posting_requirements', ['id' => $manual->id]);
        $this->assertDatabaseCount('job_posting_requirements', 3);
    }

    public function test_invalid_evidence_marks_operation_invalid_and_persists_nothing(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
            'description' => 'Strong PHP knowledge is required.',
        ]);
        $response = json_encode([
            'requirements' => [[
                'type' => 'skill',
                'importance' => 'required',
                'label' => 'Laravel',
                'evidence' => 'Laravel experience is required.',
            ]],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->actionReturning($response)->execute($posting);
            $this->fail('The invalid evidence was accepted.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame(
                'Every extracted requirement must reference evidence from the job posting.',
                $exception->getMessage(),
            );
        }

        $operation = AiOperation::query()->sole();

        $this->assertSame('invalid_response', $operation->status);
        $this->assertSame('UnexpectedValueException', $operation->error_code);
        $this->assertSame(
            'The AI response did not match the required structure.',
            $operation->error_message,
        );
        $this->assertDatabaseCount('job_posting_requirements', 0);
    }

    public function test_empty_posting_is_rejected_before_ai_operation_starts(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Backend Developer',
        ]);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->actionReturning('{"requirements":[]}')->execute($posting);
        } finally {
            $this->assertDatabaseCount('ai_operations', 0);
            $this->assertDatabaseCount('job_posting_requirements', 0);
        }
    }

    private function actionReturning(string $content): ExtractJobPostingRequirements
    {
        $provider = new class($content) implements AiProvider
        {
            public function __construct(
                private readonly string $content,
            ) {
            }

            public function key(): string
            {
                return 'structured-local';
            }

            public function isPaid(): bool
            {
                return false;
            }

            public function generate(AiRequest $request): AiResponse
            {
                return new AiResponse(
                    content: $this->content,
                    provider: $this->key(),
                    model: 'structured-local-v1',
                    requestHash: $request->hash(),
                    responseHash: hash('sha256', $this->content),
                    inputTokens: 0,
                    outputTokens: 0,
                    durationMs: 0,
                    metadata: ['structured' => true],
                );
            }
        };
        $gateway = new AiGateway(
            providers: [$provider],
            defaultProvider: 'structured-local',
            enabled: true,
            allowPaidProviders: false,
        );

        return new ExtractJobPostingRequirements(
            new AiOperationRunner($gateway),
        );
    }
}
