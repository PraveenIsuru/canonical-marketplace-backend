<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-21 Open confirmation for a product the catalogue already holds.
 *
 * The product is named by id rather than by slug, because this arrives from the match
 * screen, which was handed candidate ids by EP-20.
 */
final class StartConfirmationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
