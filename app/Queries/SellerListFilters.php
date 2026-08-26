<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Http\Request;

/**
 * The filter and sort inputs for the seller list, already validated.
 *
 * A value object rather than an array, so the query cannot silently read a key that
 * was never validated, and so the "do we have a location" question has one answer
 * rather than being re-derived at each use.
 */
final readonly class SellerListFilters
{
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $variantId = null,
        public ?float $maxDistanceKm = null,
        public ?int $maxPriceMinor = null,
        public ?float $minRating = null,
        public bool $availableOnly = false,
        public ?string $sort = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'variant_id' => ['nullable', 'integer'],
            'max_distance_km' => ['nullable', 'numeric', 'min:0'],
            'max_price_minor' => ['nullable', 'integer', 'min:0'],
            'min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'available_only' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:distance,price,rating'],
        ]);

        return new self(
            latitude: isset($validated['lat']) ? (float) $validated['lat'] : null,
            longitude: isset($validated['lng']) ? (float) $validated['lng'] : null,
            variantId: isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            maxDistanceKm: isset($validated['max_distance_km']) ? (float) $validated['max_distance_km'] : null,
            maxPriceMinor: isset($validated['max_price_minor']) ? (int) $validated['max_price_minor'] : null,
            minRating: isset($validated['min_rating']) ? (float) $validated['min_rating'] : null,
            availableOnly: (bool) ($validated['available_only'] ?? false),
            sort: $validated['sort'] ?? null,
        );
    }

    /**
     * Both coordinates or neither.
     *
     * A latitude without a longitude is not half a location, it is no location, and
     * treating it as one would produce a distance measured from the prime meridian.
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
