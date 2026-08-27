<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-32 Writing a post or a reply, per section 11.10.
 *
 * Verification is **not** checked here. It is a domain rule about this user and this
 * product rather than a shape rule about the request, and it lives in CommunityService
 * where any future caller passes through it too.
 */
final class CreateCommunityPostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Long enough for a real observation, short enough that the discussion stays
            // readable. A review site would want more; this is a place for specifics.
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            // Omitted for a top level post. A parent naming a reply is refused in the
            // service, because that is a fact about the thread rather than the payload.
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
