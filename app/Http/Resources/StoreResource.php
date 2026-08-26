<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public store profile.
 *
 * Only ever returned for a live store. A dark store is not visible to buyers, so the
 * endpoint answers 404 rather than serving an empty profile.
 *
 * @mixin Store
 */
class StoreResource extends JsonResource
{
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
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'is_live' => $this->is_live,
            'listings' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($attachment) => [
                'attachment_id' => $attachment->id,
                'variant_id' => $attachment->variant_id,
                'attribute_values' => (object) ($attachment->variant->attribute_values ?? []),
                'price_minor' => (int) $attachment->price_minor,
                'currency' => $attachment->currency,
                'is_available' => $attachment->is_available,
                'product' => [
                    'id' => $attachment->product->id,
                    'slug' => $attachment->product->slug,
                    'name' => $attachment->product->name,
                ],
            ])->all()),
        ];
    }
}
