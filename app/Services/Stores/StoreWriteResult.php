<?php

declare(strict_types=1);

namespace App\Services\Stores;

use App\Models\Store;

/**
 * A store write, plus whether geocoding failed while performing it.
 *
 * The flag travels with the store rather than being inferred by the caller from null
 * coordinates. Those two are not the same question: an update that failed to re-geocode
 * keeps its previous coordinates, so the store has a location and the geocode still
 * failed.
 */
final readonly class StoreWriteResult
{
    public function __construct(
        public Store $store,
        public bool $geocodingFailed,
    ) {}
}
