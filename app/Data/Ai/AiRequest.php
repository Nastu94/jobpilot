<?php

namespace App\Data\Ai;

use JsonException;

final readonly class AiRequest
{
    public function __construct(
        public string $operationType,
        public string $input,
        public ?string $instruction = null,
        public array $options = [],
        public array $metadata = [],
    ) {
    }

    public function providerInput(): string
    {
        if ($this->instruction === null || $this->instruction === '') {
            return $this->input;
        }

        return $this->instruction."\n\n".$this->input;
    }

    public function hash(): string
    {
        $payload = json_encode([
            'operation_type' => $this->operationType,
            'instruction' => $this->instruction,
            'input' => $this->input,
            'options' => $this->options,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }
}
