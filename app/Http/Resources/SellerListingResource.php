<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the seller list.
 *
 * The contact block is returned to anonymous callers. That disclosure is the purpose
 * of the endpoint, not an oversight: the platform works on contact and redirect, and
 * a buyer who cannot see how to reach a seller has no way to buy anything.
 *
 * Wraps a raw query row rather than a model, because the query is hand written for
 * PostGIS distance and joins across three tables.
 */
class SellerListingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'store' => [
                'id' => (int) $row->store_id,
                'name' => $row->store_name,
                'category' => $row->store_category,
                'contact_email' => $row->contact_email,
                'contact_phone' => $row->contact_phone,
                'address_line' => $row->address_line,
                'city' => $row->city,
                'latitude' => (float) $row->latitude,
                'longitude' => (float) $row->longitude,
                'rating' => $row->rating === null ? null : (float) $row->rating,
            ],
            'variant_id' => (int) $row->variant_id,
            /*
             * Decoded to an associative array and then cast, so an empty combination
             * serialises as {} rather than [].
             *
             * A product with no attributes stores its default variant as an empty JSON
             * array, and json_decode would hand that straight back as [], which is a
             * different type from the object every other variant produces. The
             * frontend caught this: one endpoint said {} and this one said [] for the
             * same variant.
             */
            'attribute_values' => (object) (json_decode((string) $row->attribute_values, true) ?? []),
            'price_minor' => (int) $row->price_minor,
            'currency' => $row->currency,
            'is_available' => (bool) $row->is_available,
            /*
             * Null, not zero, when the caller supplied no coordinates. Zero would read
             * as "at your doorstep"; null tells the client to render nothing.
             */
            'distance_km' => $row->distance_km === null ? null : round((float) $row->distance_km, 2),
        ];
    }
}
