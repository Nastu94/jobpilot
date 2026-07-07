<?php

namespace App\Data\Ai;

final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public string $requestHash,
        public string $responseHash,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $durationMs = null,
        public array $metadata = [],
    ) {
    }
}
