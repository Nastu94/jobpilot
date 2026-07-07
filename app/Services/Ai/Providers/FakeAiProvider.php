<?php

namespace App\Services\Ai\Providers;

use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;

class FakeAiProvider implements AiProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $content = json_encode([
            'provider' => 'fake',
            'operation_type' => $request->operationType,
            'status' => 'simulated',
        ], JSON_THROW_ON_ERROR);

        return new AiResponse(
            content: $content,
            provider: $this->key(),
            model: 'deterministic-fake-v1',
            requestHash: $request->hash(),
            responseHash: hash('sha256', $content),
            inputTokens: 0,
            outputTokens: 0,
            durationMs: 0,
            metadata: ['simulated' => true],
        );
    }
}
