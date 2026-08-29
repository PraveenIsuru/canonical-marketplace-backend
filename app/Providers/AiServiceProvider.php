<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiProvider;
use App\Contracts\GeocodingProvider;
use App\Services\Ai\AnthropicTransport;
use App\Services\Ai\FakeAiProvider;
use App\Services\Ai\GeminiTransport;
use App\Services\Ai\PromptedAiProvider;
use App\Services\Geocoding\FakeGeocodingProvider;
use App\Services\Geocoding\LocationIqProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds the vendor provider interfaces to whichever adapters configuration names.
 *
 * The single place in the application that knows which vendors are in use. Switching
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

                /*
                 * The prompts are the same class whoever answers. Only the transport
                 * differs, so a new provider is a new transport plus an arm here, and
                 * can never be a second copy of the prompts.
                 */
                'anthropic' => new PromptedAiProvider(new AnthropicTransport(
                    apiKey: (string) config('ai.anthropic.key'),
                    model: (string) config('ai.anthropic.model'),
                    timeoutSeconds: (int) config('ai.anthropic.timeout'),
                )),

                'gemini' => new PromptedAiProvider(new GeminiTransport(
                    apiKey: (string) config('ai.gemini.key'),
                    model: (string) config('ai.gemini.model'),
                    timeoutSeconds: (int) config('ai.gemini.timeout'),
                )),

                // A typo in AI_PROVIDER should fail loudly at boot rather than
                // silently falling back to a provider nobody chose.
                default => throw new InvalidArgumentException(
                    "Unknown AI provider [{$provider}]. Supported: fake, anthropic, gemini.",
                ),
            };
        });

        $this->app->singleton(GeocodingProvider::class, function (): GeocodingProvider {
            $provider = (string) config('geocoding.provider');

            return match ($provider) {
                'fake' => new FakeGeocodingProvider(
                    shouldFail: (bool) config('geocoding.fake_should_fail'),
                ),

                'locationiq' => new LocationIqProvider(
                    apiKey: (string) config('geocoding.locationiq.key'),
                    timeoutSeconds: (int) config('geocoding.locationiq.timeout'),
                ),

                default => throw new InvalidArgumentException(
                    "Unknown geocoding provider [{$provider}]. Supported: fake, locationiq.",
                ),
            };
        });
    }
}
