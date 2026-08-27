<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-22 Submit confirmation answers.
 *
 * The most consequential request in the platform. What it lets through either attaches
 * a seller to a shared record or opens a proposal that blocks them from selling for
 * three days, and neither is undone by a refresh.
 *
 * Note what is **not** validated here: whether every question was answered. That is
 * checked against the stored session, because only the session knows what was asked,
 * and it carries its own registered code rather than `validation_failed`. A client that
 * supplied both the questions and the answers could otherwise report itself complete.
 */
final class SubmitConfirmationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'uuid'],

            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'string', 'max:2000'],

            // At least one. A seller confirming a product is doing it in order to sell
            // something, and an attachment to no version at all lists nothing.
            'variant_ids' => ['required', 'array', 'min:1'],
            'variant_ids.*' => ['required', 'integer', 'min:1'],

            /*
             * Integer in the smallest currency unit, and above zero. The API never
             * accepts a decimal price, so a float is a refusal rather than a value to
             * round.
             */
            'price_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
