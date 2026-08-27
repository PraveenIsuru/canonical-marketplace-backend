<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EP-35 Submitting a verification photograph, per section 11.10.
 *
 * Only presence is validated here. Format and size are checked by
 * `ImageUpload::assertAcceptable`, which every upload in the platform shares, because
 * the contract registers `unsupported_media_type` and `file_too_large` as codes in
 * their own right and a `mimetypes:` or `max:` rule would return `validation_failed`
 * instead. The client branches on the code, so a wrong sized photograph and a missing
 * field must not look the same to it.
 *
 * The file is destroyed the moment verification concludes, and no response ever names
 * where it was.
 */
final class SubmitVerificationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file'],
        ];
    }
}
