<?php

use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\Providers\OllamaProvider;

return [
    'enabled' => env('AI_ENABLED', false),

    'default' => env('AI_PROVIDER', 'fake'),

    'allow_paid_providers' => env('AI_ALLOW_PAID_PROVIDERS', false),

    'payloads_stored' => false,

    'providers' => [
        'fake' => [
            'driver' => FakeAiProvider::class,
        ],

        'ollama' => [
            'driver' => OllamaProvider::class,
            'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434/api'),
            'model' => env('OLLAMA_MODEL'),
            'timeout_seconds' => (int) env('OLLAMA_TIMEOUT_SECONDS', 120),
        ],
    ],
];
