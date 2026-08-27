<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-17 manual pin placement.
 *
 * Bounds are validated rather than assumed. A latitude of 200 is not a location, and
 * letting it reach the generated geography column would place the store somewhere that
 * does not exist and quietly poison every proximity sorted list it appears in.
 */
final class PlacePinRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
