<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of a seller's own listings, after they changed it (EP-25), per section 11.9.
 *
 * Carries the product and the combination as well as the price, because the client
 * that just edited a row needs enough to re render it without a second call.
 *
 * @property Attachment $resource
 */
final class ListingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attachment = $this->resource;

        return [
            'attachment_id' => $attachment->id,
            'variant_id' => $attachment->variant_id,
            'product' => [
                'id' => $attachment->product->id,
                'slug' => $attachment->product->slug,
                'name' => $attachment->product->name,
            ],
            // Cast so an empty combination serialises as {} rather than [], which is
            // what a single default variant has and what every other resource emits.
            'attribute_values' => (object) $attachment->variant->attribute_values,
            // Integer in the smallest currency unit. Divided by 100 for display only,
            // and never here.
            'price_minor' => $attachment->price_minor,
            'currency' => $attachment->currency,
            'is_available' => $attachment->is_available,
        ];
    }
}
