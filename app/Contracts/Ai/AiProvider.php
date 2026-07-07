<?php

namespace App\Contracts\Ai;

use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;

interface AiProvider
{
    public function key(): string;

    public function isPaid(): bool;

    public function generate(AiRequest $request): AiResponse;
}
