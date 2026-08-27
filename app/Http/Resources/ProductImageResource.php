<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One image on a canonical product record (EP-48).
 *
 * The storage path never appears. Clients receive a URL, so moving the disk to object
 * storage changes what is served without changing what is promised.
 *
 * `uploaded_by_user_id` is returned because the uploader is recorded openly, but it
 * confers nothing. No endpoint lets an uploader remove or replace their own image, and
 * deletion is an administrator action.
 *
 * @mixin ProductImage
 */
final class ProductImageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'mime_type' => $this->mime_type,
            'position' => $this->position,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
        ];
    }
}
