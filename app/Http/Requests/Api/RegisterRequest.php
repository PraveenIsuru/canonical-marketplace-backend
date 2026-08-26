<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

/**
 * EP-01. Reuses the same name, email, and password rules the starter's own
 * registration uses, so the two paths cannot drift apart on what a valid account is.
 */
final class RegisterRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @return array<string, Unique|ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
