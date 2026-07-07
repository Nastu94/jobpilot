<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class OllamaProvider implements AiProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $model,
        private readonly int $timeoutSeconds = 120,
    ) {
        $this->assertLocalBaseUrl();
    }

    public function key(): string
    {
        return 'ollama';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function generate(AiRequest $request): AiResponse
    {
        if ($this->model === null || trim($this->model) === '') {
            throw new RuntimeException('OLLAMA_MODEL must be configured before using Ollama.');
        }

        $startedAt = hrtime(true);

        $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->post('generate', [
                'model' => $this->model,
                'prompt' => $request->providerInput(),
                'stream' => false,
                'options' => $request->options,
            ])
            ->throw();

        $content = (string) $response->json('response', '');
        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);
        $inputTokens = (int) $response->json('prompt_eval_count', 0);
        $outputTokens = (int) $response->json('eval_count', 0);

        return new AiResponse(
            content: $content,
            provider: $this->key(),
            model: (string) $response->json('model', $this->model),
            requestHash: $request->hash(),
            responseHash: hash('sha256', $content),
            inputTokens: $inputTokens === 0 ? null : $inputTokens,
            outputTokens: $outputTokens === 0 ? null : $outputTokens,
            durationMs: $durationMs,
            metadata: [
                'done_reason' => $response->json('done_reason'),
                'total_duration_ns' => $response->json('total_duration'),
            ],
        );
    }

    private function assertLocalBaseUrl(): void
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new InvalidArgumentException('Ollama must use a local loopback address in zero-cost mode.');
        }
    }
}
