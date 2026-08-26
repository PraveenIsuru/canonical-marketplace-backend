<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** EP-06. */
final class ResetPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => $this->passwordRules(),
        ];
    }
}
