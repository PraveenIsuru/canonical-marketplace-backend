<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Http\Request;

/**
 * One product in full, for an administrator (EP-61, and what EP-43 answers with).
 *
 * Per section 11.12. Extends the summary so a field added there cannot be forgotten
 * here, including the one that is deliberately absent from both.
 *
 * **Every generated combination appears, including ones no seller carries.** Section
 * 11.5 requires it of the public shape and it matters more here, not less: an
 * administrator screen that hid empty combinations would be the first place somebody
 * got the idea a combination can be removed. Nothing in this platform removes one.
 */
final class AdminProductDetailResource extends AdminProductSummaryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        // Counted per variant in one query rather than per row, since a record with
        // sixty combinations would otherwise be sixty queries.
        $sellersByVariant = Attachment::query()
            ->where('product_id', $product->id)
            ->selectRaw('variant_id, count(distinct store_id) as sellers')
            ->groupBy('variant_id')
            ->pluck('sellers', 'variant_id');

        return [
            ...parent::toArray($request),

            'description' => $product->description,

            // Cast so an empty map serialises as {} rather than [], matching every
            // other resource that carries this field.
            'specifications' => (object) $product->specifications,

            'attributes' => $product->productAttributes->map(
                static fn (ProductAttribute $attribute): array => [
                    'id' => $attribute->id,
                    'name' => $attribute->name,
                    'options' => $attribute->options,
                    'position' => $attribute->position,
                ],
            )->all(),

            'variants' => $product->variants->map(
                static fn (Variant $variant): array => [
                    'id' => $variant->id,
                    'attribute_values' => (object) $variant->attribute_values,
                    'is_default' => $variant->is_default,
                    'seller_count' => (int) ($sellersByVariant[$variant->id] ?? 0),
                ],
            )->all(),

            'images' => $product->images->map(
                static fn (ProductImage $image): array => [
                    'id' => $image->id,
                    // A URL, never the storage path. The path is internal and the model
                    // hides it for the same reason.
                    'url' => $image->url(),
                    'mime_type' => $image->mime_type,
                    'position' => $image->position,
                ],
            )->all(),
        ];
    }
}
