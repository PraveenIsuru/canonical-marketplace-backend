<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-37 Saving a variant, per section 11.9.
 *
 * By variant rather than by product, because both alerts that read the wishlist are
 * about one specific combination. Saving "the phone" cannot be acted on when the 128GB
 * and the 256GB move in price independently.
 */
final class AddWishlistItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
