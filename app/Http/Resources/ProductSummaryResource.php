<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The card shape used by catalogue listings and search results.
 *
 * lowest_price_minor and seller_count are null and zero for a product no live store
 * carries. Such a product is still returned, because it stays visible in the catalogue.
 *
 * @mixin Product
 */
class ProductSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $primary = $this->images->first();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'primary_image' => $primary === null ? null : [
                'id' => $primary->id,
                'url' => $primary->url(),
                'mime_type' => $primary->mime_type,
                'position' => $primary->position,
            ],
            // Integer minor units or null. Never a decimal, and never zero as a stand
            // in for "no price", which would read as free.
            'lowest_price_minor' => $this->lowest_price_minor === null ? null : (int) $this->lowest_price_minor,
            'currency' => $this->lowest_price_minor === null ? null : 'LKR',
            'seller_count' => (int) ($this->seller_count ?? 0),
        ];
    }
}
