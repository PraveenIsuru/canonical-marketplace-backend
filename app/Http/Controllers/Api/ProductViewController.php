<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RecordProductViewRequest;
use App\Models\Product;
use App\Services\Analytics\ViewRecordingService;
use Illuminate\Http\JsonResponse;

/**
 * Recording that somebody looked at a product (EP-52).
 *
 * Public, and public in the strict sense the contract means: it resolves no session,
 * records no user, and behaves identically whether or not a token happens to be
 * present. A view counter that quietly identified its visitors would be the one place
 * in the catalogue where anonymity stopped.
 *
 * Thin. Which store the view belongs to is decided in ViewRecordingService.
 */
final class ProductViewController extends Controller
{
    public function __construct(private readonly ViewRecordingService $views) {}

    /**
     * EP-52 Record a product page view.
     *
     * The response echoes the store the view was actually attributed to, which is null
     * when no context was sent and also when the store sent does not carry this
     * product. Saying so makes the difference visible to a client that expected
     * otherwise, rather than leaving it to be discovered in an analytics screen weeks
     * later.
     */
    public function store(RecordProductViewRequest $request, Product $product): JsonResponse
    {
        $attributedTo = $this->views->record($product, $request->storeId());

        return response()->json([
            'data' => [
                'recorded' => true,
                'store_id' => $attributedTo,
            ],
        ], 201);
    }
}
