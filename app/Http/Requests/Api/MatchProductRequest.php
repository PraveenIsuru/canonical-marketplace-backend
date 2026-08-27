<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-20 Run matching over a product a seller wants to list.
 *
 * Accepts multipart/form-data, because matching operates on text and on an uploaded
 * photograph. There is no barcode or GTIN field, and adding one would not be a
 * shortcut: the platform matches on what a product is, not on a code that only some
 * products carry and that sellers frequently mistype.
 */
final class MatchProductRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],

            /*
             * Only checked to be a file here. Format and size are asserted separately,
             * because the contract registers `unsupported_media_type` and
             * `file_too_large` as their own codes, and a `mimes:` or `max:` rule would
             * report both as `validation_failed` instead.
             */
            'image' => ['nullable', 'file'],
        ];
    }
}
