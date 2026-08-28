<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Queries\StoreAnalytics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A seller's own view counts (EP-39), per section 11.11.
 *
 * Nothing here identifies a visitor. The table behind it holds no user id on any row
 * this platform writes, and even if it did, telling a seller who looked at their shop
 * is not what this endpoint is for.
 *
 * @property StoreAnalytics $resource
 */
final class StoreAnalyticsResource extends JsonResource
{
    public function __construct(StoreAnalytics $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $analytics = $this->resource;

        return [
            // Echoed back because both bounds are optional, so a client that sent
            // neither still learns which thirty days it is looking at.
            'from' => $analytics->from,
            'to' => $analytics->to,
            'store_views' => $analytics->storeViews,
            'product_views' => $analytics->productViews,
            'daily' => $analytics->daily,
            'products' => $analytics->products,
        ];
    }
}
