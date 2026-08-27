<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddWishlistItemRequest;
use App\Http\Resources\WishlistItemResource;
use App\Services\Listings\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A buyer's saved variants (EP-36, EP-37, EP-38).
 *
 * Auth level rather than seller level. A user who runs a store keeps a wishlist like
 * anyone else: one account holds both roles, and there is no mode switch anywhere in
 * this platform.
 */
final class WishlistController extends Controller
{
    public function __construct(private readonly WishlistService $wishlist) {}

    /** EP-36 What this buyer is watching, with the cheapest current listing for each. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $this->wishlist->forUser($request->user(), $this->perPage($request));

        return WishlistItemResource::collection($items);
    }

    /**
     * EP-37 Save a variant.
     *
     * Answers **200 with the existing item** when it was already saved, rather than a
     * conflict. A buyer pressing save twice meant it twice, and there is nothing for
     * the interface to apologise for.
     */
    public function store(AddWishlistItemRequest $request): JsonResponse
    {
        $item = $this->wishlist->add(
            $request->user(),
            (int) $request->validated('variant_id'),
        );

        return (new WishlistItemResource($item))->response()->setStatusCode(200);
    }

    /** EP-38 Remove a saved variant, by the wishlist row's own id. */
    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->wishlist->remove($request->user(), $item);

        return response()->json(['data' => ['removed' => true]]);
    }

    /** Capped at 100 by the contract, section 2. */
    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('per_page', 20)));
    }
}
