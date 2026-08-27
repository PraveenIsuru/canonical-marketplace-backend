<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Contracts\GeocodingProvider;

/**
 * The geocoding provider used in development and in every test.
 *
 * The failing mode is not a convenience. Store creation returning 201 with null
 * coordinates instead of a 4xx is the single most surprising behaviour in seller
 * onboarding, and forcing this adapter to fail is the only honest way to prove it.
 */
final class FakeGeocodingProvider implements GeocodingProvider
{
    /**
     * Known Sri Lankan cities, so a seeded or hand entered address resolves somewhere
     * real and distance sorting stays meaningful in development.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const CITIES = [
        'colombo' => [6.9271, 79.8612],
        'kandy' => [7.2906, 80.6337],
        'galle' => [6.0535, 80.2210],
        'jaffna' => [9.6615, 80.0255],
        'negombo' => [7.2083, 79.8358],
        'matara' => [5.9549, 80.5550],
    ];

    public function __construct(private readonly bool $shouldFail = false) {}

    public function geocode(string $addressLine, string $city): GeocodingResult
    {
        if ($this->shouldFail) {
            return GeocodingResult::unresolved();
        }

        $key = mb_strtolower(trim($city));

        if (! isset(self::CITIES[$key])) {
            // An unknown city is a genuine failure rather than a guess. Inventing a
            // location would put a store somewhere it is not, which is worse than
            // asking the seller to place the pin themselves.
            return GeocodingResult::unresolved();
        }

        [$latitude, $longitude] = self::CITIES[$key];

        /*
         * Nudged by a stable hash of the address so two stores in one city do not land
         * on the same point. Deterministic, so a test asserting distance ordering does
         * not change answer between runs.
         */
        $offset = (crc32($addressLine) % 100) / 2000;

        return GeocodingResult::found($latitude + $offset, $longitude + $offset);
    }
}
