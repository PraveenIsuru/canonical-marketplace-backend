<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Attachment;
use App\Models\Store;
use App\Services\Listings\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A seller managing their own listings (EP-25, EP-26).
 *
 * The whole of a seller's write access to the catalogue, and it is deliberately two
 * fields wide: what they charge, and whether they have it. Everything about what the
 * product *is* belongs to the shared record and changes only through peer review.
 *
 * Thin. Ownership, the price drop decision, and the live flag all live in
 * ListingService.
 */
final class ListingController extends Controller
{
    public function __construct(private readonly ListingService $listings) {}

    /**
     * EP-25 Change a price, an availability flag, or both.
     *
     * Lowering a price queues an alert to the buyers watching that variant. Raising one
     * queues nothing.
     */
    public function update(UpdateListingRequest $request, int $attachment): ListingResource
    {
        $store = $this->callerStore($request);
        $found = $this->find($attachment);

        $updated = $this->listings->update($found, $store, $request->changes());

        return new ListingResource($updated->load(['variant', 'product']));
    }

    /**
     * EP-26 Stop carrying a variant.
     *
     * The response carries the store's live flag afterwards, because a seller removing
     * their last listing has just made their store invisible to buyers and that is the
     * one thing they need to be told at that moment.
     *
     * The product is untouched. It is platform owned, outlives every seller on it, and
     * stays at its own URL reporting no sellers.
     */
    public function destroy(Request $request, int $attachment): JsonResponse
    {
        $store = $this->callerStore($request);
        $found = $this->find($attachment);

        $storeIsLive = $this->listings->detach($found, $store);

        return response()->json([
            'data' => [
                'detached' => true,
                'store_is_live' => $storeIsLive,
            ],
        ]);
    }

    private function find(int $id): Attachment
    {
        return Attachment::find($id)
            ?? throw ApiException::notFound('That listing does not exist.');
    }

    private function callerStore(Request $request): Store
    {
        return $request->user()->store ?? throw ApiException::storeRequired();
    }
}
