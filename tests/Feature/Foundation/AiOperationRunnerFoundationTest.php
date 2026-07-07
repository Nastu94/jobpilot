<?php

namespace Tests\Feature\Foundation;

use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiOperationContext;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiOperationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiOperationRunnerFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_records_completed_operation_without_storing_payloads(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.default', 'fake');
        config()->set('ai.allow_paid_providers', false);
        Http::fake();

        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $posting = JobPosting::create([
            'profile_id' => $profile->id,
            'title' => 'Laravel Developer',
        ]);
        $request = new AiRequest(
            operationType: 'profile_summary_generation',
            input: 'PRIVATE CV CONTENT THAT MUST NOT BE STORED',
            instruction: 'Create a concise summary.',
        );
        $context = new AiOperationContext(
            profileId: $profile->id,
            jobPostingId: $posting->id,
            promptTemplateKey: 'profile_summary',
            promptTemplateVersion: '1.0.0',
        );

        $result = $this->app->make(AiOperationRunner::class)->run($request, $context);
        $operation = $result->operation;

        $this->assertSame('completed', $operation->status);
        $this->assertSame('fake', $operation->provider);
        $this->assertSame('deterministic-fake-v1', $operation->model);
        $this->assertSame($request->hash(), $operation->request_hash);
        $this->assertSame($result->response->responseHash, $operation->response_hash);
        $this->assertSame(0, $operation->input_tokens);
        $this->assertSame(0, $operation->output_tokens);
        $this->assertSame(0, $operation->duration_ms);
        $this->assertSame(0, $operation->cost_micros);
        $this->assertFalse($operation->payloads_stored);
        $this->assertSame(['simulated' => true], $operation->metadata);
        $this->assertSame('profile_summary', $operation->prompt_template_key);
        $this->assertSame('1.0.0', $operation->prompt_template_version);
        $this->assertNotNull($operation->started_at);
        $this->assertNotNull($operation->completed_at);
        $this->assertTrue($operation->profile->is($profile));
        $this->assertTrue($operation->jobPosting->is($posting));
        $this->assertNotContains(
            'PRIVATE CV CONTENT THAT MUST NOT BE STORED',
            array_values($operation->getAttributes()),
        );
        Http::assertNothingSent();
    }

    public function test_runner_records_sanitized_failure_and_rethrows_exception(): void
    {
        $profile = Profile::create(['user_id' => User::factory()->create()->id]);
        $provider = new class implements AiProvider
        {
            public function key(): string
            {
                return 'failing-local';
            }

            public function isPaid(): bool
            {
                return false;
            }

            public function generate(AiRequest $request): AiResponse
            {
                throw new RuntimeException('PRIVATE CV CONTENT FROM PROVIDER ERROR');
            }
        };
        $runner = new AiOperationRunner(new AiGateway(
            providers: [$provider],
            defaultProvider: 'failing-local',
            enabled: true,
            allowPaidProviders: false,
        ));
        $request = new AiRequest(
            operationType: 'targeted_resume_generation',
            input: 'PRIVATE INPUT CONTENT',
        );

        try {
            $runner->run(
                request: $request,
                context: new AiOperationContext(profileId: $profile->id),
            );
            $this->fail('The provider exception was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PRIVATE CV CONTENT FROM PROVIDER ERROR', $exception->getMessage());
        }

        $operation = AiOperation::query()->sole();

        $this->assertSame('failed', $operation->status);
        $this->assertSame('failing-local', $operation->provider);
        $this->assertSame('RuntimeException', $operation->error_code);
        $this->assertSame('The AI operation failed before completion.', $operation->error_message);
        $this->assertSame($request->hash(), $operation->request_hash);
        $this->assertNull($operation->response_hash);
        $this->assertSame(0, $operation->cost_micros);
        $this->assertFalse($operation->payloads_stored);
        $this->assertNotNull($operation->duration_ms);
        $this->assertNotNull($operation->completed_at);
        $this->assertNotContains(
            'PRIVATE CV CONTENT FROM PROVIDER ERROR',
            array_values($operation->getAttributes()),
        );
        $this->assertNotContains(
            'PRIVATE INPUT CONTENT',
            array_values($operation->getAttributes()),
        );
    }

    public function test_disabled_gateway_does_not_create_operation_record(): void
    {
        config()->set('ai.enabled', false);

        try {
            $this->app->make(AiOperationRunner::class)->run(
                request: new AiRequest(
                    operationType: 'profile_summary_generation',
                    input: 'Example profile data.',
                ),
                context: new AiOperationContext(profileId: 999),
            );
            $this->fail('The disabled gateway accepted an operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AI is not enabled.', $exception->getMessage());
        }

        $this->assertDatabaseCount('ai_operations', 0);
    }
}
