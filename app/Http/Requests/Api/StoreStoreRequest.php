<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-16 store registration.
 *
 * Note what is absent: latitude, longitude, geocode_source, and is_live. Coordinates
 * come from the geocoding provider or the pin endpoint, and visibility is derived from
 * attachment count. A payload must never be able to set any of them.
 */
final class StoreStoreRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'contact_email' => ['required', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'address_line' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
        ];
    }
}
