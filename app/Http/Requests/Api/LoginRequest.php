<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-02.
 *
 * Deliberately thin. The password is not checked against the complexity rules here,
 * because rejecting a login for a weak password would tell an attacker that the
 * password format was wrong rather than that the credentials were.
 */
final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
