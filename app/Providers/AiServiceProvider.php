<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Services\Ai\AnthropicAiProvider;
use App\Services\Ai\FakeAiProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds the AI provider interface to whichever adapter configuration names.
 *
 * The single place in the application that knows which vendor is in use. Switching
 * provider is a config change, and every feature is insulated from it.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function (): AiProvider {
            $provider = (string) config('ai.provider');

            return match ($provider) {
                'fake' => new FakeAiProvider(
                    shouldFail: (bool) config('ai.fake_should_fail'),
                ),

                'anthropic' => new AnthropicAiProvider(
                    apiKey: (string) config('ai.anthropic.key'),
                    model: (string) config('ai.anthropic.model'),
                    timeoutSeconds: (int) config('ai.anthropic.timeout'),
                ),

                // A typo in AI_PROVIDER should fail loudly at boot rather than
                // silently falling back to a provider nobody chose.
                default => throw new InvalidArgumentException(
                    "Unknown AI provider [{$provider}]. Supported: fake, anthropic.",
                ),
            };
        });
    }
}
