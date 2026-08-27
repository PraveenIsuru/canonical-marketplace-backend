<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-48 Upload an image to a canonical product record.
 *
 * Format, size, and the eight image ceiling are all asserted outside these rules,
 * because each has its own registered error code and a validation rule would flatten
 * the three into `validation_failed`.
 */
final class UploadProductImageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file'],

            // Optional. Left out, the image is appended after the ones already there.
            'position' => ['nullable', 'integer', 'min:0', 'max:7'],
        ];
    }
}
