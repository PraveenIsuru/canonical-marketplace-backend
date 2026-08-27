<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The seller's own store (EP-16, EP-17, EP-18, EP-54).
 *
 * Separate from StoreResource, which is the public profile. This one carries
 * geocode_source and the live flag, which are operational details the owner needs for
 * the settings form and a buyer has no business seeing.
 *
 * @mixin Store
 */
final class OwnStoreResource extends JsonResource
{
    public function __construct(Store $store, private readonly ?bool $geocodingFailed = null)
    {
        parent::__construct($store);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'address_line' => $this->address_line,
            'city' => $this->city,
            // Null until geocoding succeeds or a pin is placed. Null is the signal the
            // client uses to route into pin placement, so it must not be defaulted.
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'geocode_source' => $this->geocode_source,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            /*
             * Derived from attachment count, never from a payload. It stays false
             * throughout onboarding: a store becomes visible to buyers only once it
             * carries something.
             */
            'is_live' => $this->is_live,

            /*
             * Present only on a write. Its position inside data follows section 11.3 of
             * the contract, which is what the client codes against.
             */
            ...($this->geocodingFailed === null ? [] : ['geocoding_failed' => $this->geocodingFailed]),
        ];
    }
}
