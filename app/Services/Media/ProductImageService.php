<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Images belonging to a canonical product record.
 *
 * They belong to the record, not to whoever uploaded them, and every store carrying
 * the product shows the same set. There are no per seller galleries, so nothing here
 * scopes an image to a store, and the uploader is recorded without gaining any right
 * to remove or replace what they added.
 *
 * Images publish immediately. There is no moderation status to set.
 */
final class ProductImageService
{
    /**
     * Stores an image against a product.
     *
     * The ceiling is checked before the file is written, so a refused upload leaves
     * nothing behind on disk to be cleaned up later.
     */
    public function add(Product $product, UploadedFile $file, User $uploader, ?int $position = null): ProductImage
    {
        if ($product->images()->count() >= ProductImage::MAX_PER_PRODUCT) {
            throw ApiException::imageLimitReached();
        }

        ImageUpload::assertAcceptable($file);

        $path = $file->store("products/{$product->id}", $this->disk());

        if ($path === false) {
            throw new ApiException(422, 'validation_failed', 'The given data was invalid.', [
                'image' => ['The image could not be stored. Please try again.'],
            ]);
        }

        return ProductImage::create([
            'product_id' => $product->id,
            'storage_path' => $path,
            // The guessed type, matching what was validated. Storing the client's claim
            // would let a response advertise a type the bytes are not.
            'mime_type' => (string) $file->getMimeType(),
            'file_size_bytes' => (int) $file->getSize(),
            'uploaded_by_user_id' => $uploader->id,
            'position' => $position ?? $this->nextPosition($product),
        ]);
    }

    /**
     * EP-49 Removes an image, row and file.
     *
     * The only deletion path for an image, and it is administrator only. A seller may
     * add one through EP-48 and may never remove one, because an uploader who could
     * remove an image could remove one a later seller relies on. Images belong to the
     * shared record, not to whoever happened to upload them.
     *
     * Actually destroyed rather than soft deleted, unlike a community post. An image is
     * not evidence of anything, and keeping a moderated one on disk serves nobody.
     *
     * Deletion is tolerant of a file that has already gone. The goal is that it is not
     * there, and it already not being there satisfies that.
     *
     * @return int how many images the record has left
     */
    public function remove(Product $product, ProductImage $image): int
    {
        Storage::disk($this->disk())->delete($image->storage_path);

        $image->delete();

        return $product->images()->count();
    }

    /**
     * The next free position.
     *
     * Positions are appended rather than packed. An explicit position is allowed to
     * collide with an existing one, and the order between two images sharing a position
     * is then unspecified but stable, which is a smaller problem than renumbering rows
     * a seller did not ask to have moved.
     */
    private function nextPosition(Product $product): int
    {
        return (int) $product->images()->max('position') + 1;
    }

    /**
     * The disk product images live on.
     *
     * Separate from the verification photograph disk on purpose. Verification
     * photographs are deleted unconditionally once verification concludes, and product
     * images are kept, so the two must never share a location. Keeping the lifecycles
     * on different disks is what makes that deletion safe to automate later.
     */
    private function disk(): string
    {
        return (string) config('filesystems.product_images', 'public');
    }
}
