<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProvider;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

class AiGateway
{
    private array $providers = [];

    public function __construct(
        iterable $providers,
        private readonly string $defaultProvider,
        private readonly bool $enabled,
        private readonly bool $allowPaidProviders,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    public function generate(AiRequest $request, ?string $providerKey = null): AiResponse
    {
        return $this->provider($providerKey)->generate($request);
    }

    public function provider(?string $providerKey = null): AiProvider
    {
        if (! $this->enabled) {
            throw new RuntimeException('AI is not enabled.');
        }

        $key = $providerKey ?? $this->defaultProvider;

        if (! array_key_exists($key, $this->providers)) {
            throw new InvalidArgumentException('The selected AI provider is unavailable.');
        }

        $provider = $this->providers[$key];

        if ($provider->isPaid() && ! $this->allowPaidProviders) {
            throw new DomainException('This provider is not enabled for the current cost policy.');
        }

        return $provider;
    }
}
