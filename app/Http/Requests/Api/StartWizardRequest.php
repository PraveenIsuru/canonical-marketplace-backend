<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-23 Open the listing wizard.
 *
 * The same three fields matching was run on. They are resubmitted rather than carried
 * in a token, because the wizard re-runs the match itself before it will open, and it
 * has to run against what the seller is actually claiming now.
 */
final class StartWizardRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
        ];
    }
}
