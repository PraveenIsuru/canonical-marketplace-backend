<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-07.
 *
 * Bounds are checked rather than assumed. A latitude of 200 is not a location, and
 * letting it reach PostGIS would produce a point that silently poisons every
 * proximity alert calculated against it.
 */
final class UpdateLocationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
