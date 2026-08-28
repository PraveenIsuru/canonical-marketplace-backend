<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CommunityPost;
use App\Models\Product;
use App\Models\ProductImage;
use App\Queries\PlatformMetricsQuery;
use App\Services\Admin\AdminModerationService;
use App\Services\Media\ProductImageService;
use Illuminate\Http\JsonResponse;

/**
 * Moderation and the platform snapshot (EP-44, EP-49, EP-45).
 *
 * Three administrator actions that share nothing except who may perform them, which is
 * why they sit together rather than each having a controller of their own.
 *
 * The two deletions have deliberately different natures. **A post is soft deleted**, so
 * the row survives and every read path hides it. **An image is destroyed**, row and
 * file, because an image is not evidence of anything and keeping a moderated one on
 * disk serves nobody.
 */
final class AdminModerationController extends Controller
{
    public function __construct(
        private readonly AdminModerationService $moderation,
        private readonly ProductImageService $images,
        private readonly PlatformMetricsQuery $metrics,
    ) {}

    /**
     * EP-44 Removes a post from the discussion.
     *
     * Soft deleted, never destroyed, and its replies go with it. There is no tombstone
     * anywhere: a removed post does not appear as a placeholder, per section 11.10.
     *
     * There is no endpoint that restores one and none is planned.
     */
    public function deletePost(int $post): JsonResponse
    {
        $found = CommunityPost::find($post)
            ?? throw ApiException::notFound('That post does not exist.');

        $repliesHidden = $this->moderation->deletePost($found);

        return response()->json([
            'data' => [
                'deleted' => true,
                'replies_hidden' => $repliesHidden,
            ],
        ]);
    }

    /**
     * EP-49 Removes an image from a record.
     *
     * The only deletion path for an image. A seller may add one through EP-48 and may
     * never remove one, because an uploader who could remove an image could remove one a
     * later seller relies on.
     *
     * Keyed by product **slug**, like every other route on the public product path, and
     * by image id within it.
     */
    public function deleteImage(Product $product, int $image): JsonResponse
    {
        $found = ProductImage::query()
            ->where('product_id', $product->id)
            ->whereKey($image)
            ->first()
            // Scoped to the product, so an image id belonging to a different record
            // answers 404 rather than being deleted from under it.
            ?? throw ApiException::notFound('That image does not exist on this product.');

        $remaining = $this->images->remove($product, $found);

        return response()->json([
            'data' => [
                'deleted' => true,
                'images_remaining' => $remaining,
            ],
        ]);
    }

    /**
     * EP-45 The platform at a glance.
     *
     * Counts rather than analytics, and **nothing here is per user**. The closest is a
     * count of people who have verified something, which is a number and not a list.
     */
    public function metrics(): JsonResponse
    {
        return response()->json(['data' => $this->metrics->snapshot()]);
    }
}
