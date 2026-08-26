<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** EP-05. */
final class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Not validated as existing. The response is identical either way, so that
        // this endpoint cannot be used to discover which addresses are registered.
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }
}
