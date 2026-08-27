<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PlacePinRequest;
use App\Http\Requests\Api\StoreStoreRequest;
use App\Http\Requests\Api\UpdateStoreRequest;
use App\Http\Resources\OwnListingsResource;
use App\Http\Resources\OwnStoreResource;
use App\Models\Store;
use App\Queries\StoreListingsQuery;
use App\Services\Stores\StoreRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller onboarding: the seller's own store (EP-16, EP-17, EP-18, EP-54).
 *
 * Thin by design. Every decision lives in StoreRegistrationService; these methods only
 * translate between HTTP and it.
 */
final class SellerStoreController extends Controller
{
    public function __construct(private readonly StoreRegistrationService $stores) {}

    /**
     * EP-16 Register a store. Auth, not Seller: the caller has no store yet.
     *
     * **A failed geocode still returns 201.** The store is created with null
     * coordinates and `geocoding_failed: true`, which the client reads as a routing
     * signal into manual pin placement, not as an error. Anything in the interface that
     * styles this as a failure is wrong.
     */
    public function store(StoreStoreRequest $request): JsonResponse
    {
        $result = $this->stores->create($request->user(), $request->validated());

        return (new OwnStoreResource($result->store, $result->geocodingFailed))
            ->response()
            ->setStatusCode(201);
    }

    /** EP-54 The caller's own store, for prefilling the settings form. */
    public function show(Request $request): OwnStoreResource
    {
        return new OwnStoreResource($this->ownStore($request));
    }

    /**
     * EP-19 What this store carries, and what it is blocked on.
     *
     * Two lists in one call, and the second is why this endpoint is not just a query
     * over attachments. A product with a proposal under review has **no attachment row
     * at all**, so a screen built from listings alone would show nothing and leave the
     * seller wondering where their submission went.
     */
    public function listings(Request $request, StoreListingsQuery $listings): OwnListingsResource
    {
        return new OwnListingsResource($listings->forStore($this->ownStore($request)));
    }

    /**
     * EP-18 Update the editable details.
     *
     * Re-geocodes only when the address or city changed, and keeps the previous
     * coordinates if that fails. Never touches `is_live`.
     */
    public function update(UpdateStoreRequest $request): OwnStoreResource
    {
        $result = $this->stores->update($this->ownStore($request), $request->validated());

        return new OwnStoreResource($result->store, $result->geocodingFailed);
    }

    /** EP-17 Place the store by hand after geocoding produced nothing. */
    public function placePin(PlacePinRequest $request): OwnStoreResource
    {
        $store = $this->stores->placePin(
            $this->ownStore($request),
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
        );

        return new OwnStoreResource($store);
    }

    /**
     * The caller's store.
     *
     * The seller middleware has already established that one exists, so reaching the
     * refusal here would mean the store vanished mid request. Guarding anyway keeps the
     * failure a clean 403 rather than a null dereference.
     */
    private function ownStore(Request $request): Store
    {
        $store = $request->user()->store;

        return $store ?? throw ApiException::storeRequired();
    }
}
