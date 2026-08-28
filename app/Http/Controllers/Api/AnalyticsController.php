<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAnalyticsRequest;
use App\Http\Resources\StoreAnalyticsResource;
use App\Models\Store;
use App\Queries\StoreAnalyticsQuery;

/**
 * A seller's own analytics (EP-39).
 *
 * Scoped to the calling store and to nothing else. There is no store id parameter
 * anywhere on this route, so there is no version of this request that reads somebody
 * else's numbers, whatever a client sends.
 *
 * Thin. The aggregation is in StoreAnalyticsQuery and the date range is decided in the
 * form request.
 */
final class AnalyticsController extends Controller
{
    public function __construct(private readonly StoreAnalyticsQuery $analytics) {}

    /** EP-39 View counts for this store's listings, over a date range. */
    public function show(StoreAnalyticsRequest $request): StoreAnalyticsResource
    {
        $store = $this->ownStore($request);

        return new StoreAnalyticsResource(
            $this->analytics->forStore($store, $request->from(), $request->to()),
        );
    }

    /**
     * The caller's store.
     *
     * The seller middleware has already established one exists, so reaching the
     * refusal here would mean it vanished mid request. Guarding anyway keeps that a
     * clean 403 rather than a null dereference.
     */
    private function ownStore(StoreAnalyticsRequest $request): Store
    {
        return $request->user()->store ?? throw ApiException::storeRequired();
    }
}
