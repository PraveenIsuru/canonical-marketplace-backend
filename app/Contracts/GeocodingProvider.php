<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Geocoding\GeocodingResult;

/**
 * Turns a postal address into coordinates.
 *
 * Mirrors the AI provider pattern: the vendor SDK lives only in an adapter, and every
 * feature depends on this interface, so switching provider is a configuration change.
 *
 * Implementations never throw for an address that cannot be resolved. They return a
 * failed result, because a seller whose address did not geocode still gets a store and
 * is routed to manual pin placement.
 */
interface GeocodingProvider
{
    public function geocode(string $addressLine, string $city): GeocodingResult;
}
