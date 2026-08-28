<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product in the administrator catalogue (EP-60), per section 11.12.
 *
 * Counts rather than contents. This list is an administrator finding a record, and the
 * four numbers on it are what say whether the record is healthy: no sellers, no images,
 * or somebody blocked on it.
 *
 * **`created_by_store_id` is absent here as everywhere else**, per section 6.
 * Administrators are not an exception to the three never exposed fields. The record is
 * platform owned and there is no reader for whom that stops being true.
 *
 * @property Product $resource
 */
class AdminProductSummaryResource extends JsonResource
{
    public function __construct(Product $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'category' => $product->category,

            // Distinct stores, not attachments. A shop carrying three combinations of
            // one product is one seller, not three.
            'seller_count' => (int) ($product->seller_count ?? 0),
            'variant_count' => (int) ($product->variants_count ?? 0),
            'image_count' => (int) ($product->images_count ?? 0),

            'current_version_number' => $product->currentVersion?->version_number,

            /*
             * Pending and escalated both count. Both mean a seller is blocked on this
             * record right now, and an administrator about to edit it should know that
             * before they do.
             */
            'has_pending_proposal' => (bool) ($product->has_pending_proposal ?? false),
        ];
    }
}
