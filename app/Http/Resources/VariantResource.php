<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One generated combination.
 *
 * Combinations with a seller count of zero are returned, never omitted. Omitting them
 * here would silently reintroduce variant removal, which the design forbids, and the
 * client renders them as having no sellers yet.
 *
 * @mixin Variant
 */
class VariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'attribute_values' => (object) $this->attribute_values,
            'is_default' => $this->is_default,
            'seller_count' => (int) ($this->seller_count ?? 0),
            'lowest_price_minor' => $this->lowest_price_minor === null ? null : (int) $this->lowest_price_minor,
        ];
    }
}
