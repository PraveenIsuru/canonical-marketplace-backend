<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Contracts\GeocodingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The real geocoding adapter.
 *
 * The only class in the application that knows LocationIQ exists. Every caller depends
 * on the GeocodingProvider interface, so switching vendor is a config change plus one
 * class in this directory.
 *
 * No test exercises this against the network. The fake adapter is bound in tests, which
 * is what keeps the suite offline and free.
 */
final class LocationIqProvider implements GeocodingProvider
{
    public function __construct(
        private readonly string $apiKey,
        /**
         * Short by design. Geocoding runs once per seller at registration, and a slow
         * provider should route the seller to manual pin placement rather than leave
         * them staring at a spinner.
         */
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function geocode(string $addressLine, string $city): GeocodingResult
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->get('https://eu1.locationiq.com/v1/search', [
                    'key' => $this->apiKey,
                    'q' => "{$addressLine}, {$city}",
                    'format' => 'json',
                    'limit' => 1,
                ]);
        } catch (Throwable $e) {
            // Unreachable and no match are the same outcome to the caller, so this is
            // logged for operations and then reported as an ordinary failure.
            Log::warning('Geocoding request failed.', ['exception' => $e->getMessage()]);

            return GeocodingResult::unresolved();
        }

        if ($response->failed()) {
            return GeocodingResult::unresolved();
        }

        $first = $response->json('0');

        if (! is_array($first)) {
            return GeocodingResult::unresolved();
        }

        // LocationIQ returns coordinates as strings. Bounds are checked in fromProvider,
        // so a nonsense answer is treated as no answer.
        return GeocodingResult::fromProvider(
            isset($first['lat']) ? (float) $first['lat'] : null,
            isset($first['lon']) ? (float) $first['lon'] : null,
        );
    }
}
