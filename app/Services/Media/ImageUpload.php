<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ApiException;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;

/**
 * The format and size rules every image upload has to satisfy.
 *
 * Deliberately not expressed as Laravel validation rules. The contract registers
 * `unsupported_media_type` and `file_too_large` as codes in their own right, and a
 * `mimes:` or `max:` rule would return `validation_failed` instead. The client branches
 * on the code, so getting it wrong would mean a wrong sized image and a missing name
 * field produced the same error to the interface.
 *
 * Shared by matching, which accepts a transient photograph, and by product image
 * upload, which keeps one. Both apply the same limits, so both use this.
 */
final class ImageUpload
{
    /**
     * Refuses an upload the platform will not accept.
     *
     * Size is checked before format. A very large file of an unsupported type is
     * refused for its size, which is the more useful thing to tell someone, since it is
     * the reason the upload took so long before failing.
     */
    public static function assertAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new ApiException(422, 'validation_failed', 'The given data was invalid.', [
                'image' => ['The image failed to upload. Please try again.'],
            ]);
        }

        if ($file->getSize() > ProductImage::MAX_BYTES) {
            throw ApiException::fileTooLarge();
        }

        /*
         * Read from the file rather than taken from the request.
         *
         * The client supplied Content-Type is whatever the browser felt like sending,
         * and on an upload it is whatever the caller chose to send. Guessing from the
         * contents is what actually establishes that a file claiming to be a PNG is one.
         */
        if (! in_array((string) $file->getMimeType(), ProductImage::ALLOWED_MIME_TYPES, true)) {
            throw ApiException::unsupportedMediaType();
        }
    }
}
