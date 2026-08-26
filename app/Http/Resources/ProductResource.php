<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The canonical record, as the statically generated product page is built from it.
 *
 * Deliberately stable and cheap. Everything volatile, meaning price, availability, and
 * distance, belongs on the seller list endpoint instead, so this payload does not need
 * revalidating when a seller edits a price.
 *
 * There is no owner field and no created_by_store_id. Records are platform owned.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'specifications' => (object) $this->specifications,
            'current_version_number' => $this->currentVersion?->version_number ?? 1,
            'seller_count' => (int) ($this->seller_count ?? 0),
            'images' => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url(),
                'mime_type' => $image->mime_type,
                'position' => $image->position,
            ])->all(),
            'attributes' => $this->productAttributes->map(fn ($attribute) => [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'options' => $attribute->options,
                'position' => $attribute->position,
            ])->all(),
        ];
    }
}
