<?php

namespace App\Providers;

use App\Services\Ai\AiGateway;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\Providers\OllamaProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FakeAiProvider::class);

        $this->app->singleton(OllamaProvider::class, fn () => new OllamaProvider(
            baseUrl: (string) config('ai.providers.ollama.base_url'),
            model: config('ai.providers.ollama.model'),
            timeoutSeconds: (int) config('ai.providers.ollama.timeout_seconds'),
        ));

        $this->app->singleton(AiGateway::class, fn ($app) => new AiGateway(
            providers: [
                $app->make(FakeAiProvider::class),
                $app->make(OllamaProvider::class),
            ],
            defaultProvider: (string) config('ai.default'),
            enabled: (bool) config('ai.enabled'),
            allowPaidProviders: (bool) config('ai.allow_paid_providers'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
