<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UploadProductImageRequest;
use App\Http\Resources\ProductImageResource;
use App\Models\Product;
use App\Services\Media\ProductImageService;
use Illuminate\Http\JsonResponse;

/**
 * Images on a canonical product record (EP-48).
 *
 * Any seller may add an image to any product, and that is deliberate rather than an
 * oversight. Images belong to the record and are shared by every store carrying it, so
 * scoping uploads to attached sellers would mean the seller who created a product
 * through the wizard could not illustrate it until they had finished attaching to it.
 *
 * Deletion is not here. It is an administrator action at M11, because an uploader who
 * could remove an image could remove one a later seller relies on.
 */
final class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImageService $images) {}

    /**
     * EP-48 Upload an image.
     *
     * The three ways this refuses each carry their own code: the eight image ceiling,
     * an unsupported format, and a file over 5 MB. The client shows a different message
     * for each, so they must not be flattened into one validation error.
     */
    public function store(UploadProductImageRequest $request, Product $product): JsonResponse
    {
        $image = $this->images->add(
            product: $product,
            file: $request->file('image'),
            uploader: $request->user(),
            position: $request->validated('position') !== null
                ? (int) $request->validated('position')
                : null,
        );

        return (new ProductImageResource($image))->response()->setStatusCode(201);
    }
}
