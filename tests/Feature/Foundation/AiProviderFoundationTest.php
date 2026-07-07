<?php

namespace Tests\Feature\Foundation;

use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Providers\OllamaProvider;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AiProviderFoundationTest extends TestCase
{
    public function test_fake_provider_is_deterministic_and_does_not_use_the_network(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.default', 'fake');
        config()->set('ai.allow_paid_providers', false);
        Http::fake();

        $request = new AiRequest(
            operationType: 'profile_summary_generation',
            input: 'Example profile data.',
        );

        $response = $this->app->make(AiGateway::class)->generate($request);

        $this->assertSame('fake', $response->provider);
        $this->assertSame('deterministic-fake-v1', $response->model);
        $this->assertSame($request->hash(), $response->requestHash);
        $this->assertSame(hash('sha256', $response->content), $response->responseHash);
        $this->assertSame(0, $response->inputTokens);
        $this->assertTrue($response->metadata['simulated']);
        Http::assertNothingSent();
    }

    public function test_ai_gateway_blocks_operations_when_disabled(): void
    {
        config()->set('ai.enabled', false);

        $this->expectException(RuntimeException::class);

        $this->app->make(AiGateway::class)->generate(new AiRequest(
            operationType: 'profile_summary_generation',
            input: 'Example profile data.',
        ));
    }

    public function test_provider_requiring_billing_is_blocked_without_explicit_permission(): void
    {
        $provider = new class implements AiProvider
        {
            public function key(): string
            {
                return 'external';
            }

            public function isPaid(): bool
            {
                return true;
            }

            public function generate(AiRequest $request): AiResponse
            {
                return new AiResponse(
                    content: 'unused',
                    provider: 'external',
                    model: 'unused',
                    requestHash: $request->hash(),
                    responseHash: hash('sha256', 'unused'),
                );
            }
        };

        $gateway = new AiGateway(
            providers: [$provider],
            defaultProvider: 'external',
            enabled: true,
            allowPaidProviders: false,
        );

        $this->expectException(DomainException::class);

        $gateway->generate(new AiRequest(
            operationType: 'targeted_resume_generation',
            input: 'Example profile data.',
        ));
    }

    public function test_ollama_provider_uses_only_the_configured_local_endpoint(): void
    {
        config()->set('ai.enabled', true);
        config()->set('ai.default', 'ollama');
        config()->set('ai.allow_paid_providers', false);
        config()->set('ai.providers.ollama.base_url', 'http://127.0.0.1:11434/api');
        config()->set('ai.providers.ollama.model', 'local-test-model');
        config()->set('ai.providers.ollama.timeout_seconds', 30);

        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'model' => 'local-test-model',
                'response' => '{"skills":["PHP"]}',
                'done_reason' => 'stop',
                'prompt_eval_count' => 12,
                'eval_count' => 8,
                'total_duration' => 250000000,
            ]),
        ]);

        $request = new AiRequest(
            operationType: 'skill_extraction',
            input: 'The candidate has PHP experience.',
            instruction: 'Return structured data only.',
            options: ['temperature' => 0],
        );

        $response = $this->app->make(AiGateway::class)->generate($request);

        $this->assertSame('ollama', $response->provider);
        $this->assertSame('local-test-model', $response->model);
        $this->assertSame('{"skills":["PHP"]}', $response->content);
        $this->assertSame(12, $response->inputTokens);
        $this->assertSame(8, $response->outputTokens);
        $this->assertSame('stop', $response->metadata['done_reason']);

        Http::assertSent(function (Request $httpRequest): bool {
            return $httpRequest->url() === 'http://127.0.0.1:11434/api/generate'
                && $httpRequest['model'] === 'local-test-model'
                && $httpRequest['stream'] === false
                && $httpRequest['options'] === ['temperature' => 0]
                && $httpRequest['prompt'] === "Return structured data only.\n\nThe candidate has PHP experience.";
        });
    }

    public function test_ollama_rejects_non_local_endpoints(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OllamaProvider(
            baseUrl: 'https://example.com/api',
            model: 'remote-model',
        );
    }
}
