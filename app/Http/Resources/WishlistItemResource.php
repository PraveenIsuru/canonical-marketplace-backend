<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One saved variant (EP-36), per section 11.9.
 *
 * `last_notified_price_minor` is **not** in this response and must not be. It is
 * bookkeeping for the alert job, and showing a buyer "we last told you about this at
 * 4,299" explains a mechanism they never asked about and invites them to read it as a
 * price history, which it is not.
 *
 * @property WishlistItem $resource
 */
final class WishlistItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $variant = $item->variant;

        /*
         * The cheapest listing anyone has right now, computed from the loaded
         * attachments rather than with a query per row.
         *
         * Only available ones count. A listing marked out of stock is not an offer, and
         * quoting its price would send a buyer to a shop that cannot sell to them.
         */
        $offers = $variant->attachments->filter(
            static fn (Attachment $attachment): bool => $attachment->is_available,
        );

        $lowest = $offers->min('price_minor');

        return [
            'id' => $item->id,
            'variant_id' => $item->variant_id,
            'attribute_values' => (object) $variant->attribute_values,
            'product' => [
                'id' => $variant->product->id,
                'slug' => $variant->product->slug,
                'name' => $variant->product->name,
                'primary_image_url' => $variant->product->images->first()?->url(),
            ],
            /*
             * Null when nobody carries it, which is a normal state rather than missing
             * data. A buyer may save a combination no seller stocks yet, and being told
             * when one appears nearby is exactly what the wishlist is for.
             */
            'lowest_price_minor' => $lowest === null ? null : (int) $lowest,
            'currency' => $offers->first()?->currency,
            'seller_count' => $offers->count(),
        ];
    }
}
