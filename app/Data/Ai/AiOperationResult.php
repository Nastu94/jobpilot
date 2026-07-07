<?php

namespace App\Data\Ai;

use App\Models\AiOperation;

final readonly class AiOperationResult
{
    public function __construct(
        public AiOperation $operation,
        public AiResponse $response,
    ) {
    }
}
