<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Catalogue\CatalogueCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public store profile (EP-13).
 */
final class StoreController extends Controller
{
    public function __construct(private readonly CatalogueCache $cache) {}

    /**
     * EP-13 One store, with what it carries.
     *
     * A dark store answers 404 rather than returning an empty profile. It is not
     * visible to buyers, so it must not be reachable by guessing an id either.
     *
     * The visibility check sits **outside** the cache deliberately. Caching it would
     * mean a store that went dark kept answering 200 from an entry written while it was
     * still live, and the one thing this endpoint must get right is not showing a shop
     * that has nothing on its shelves.
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        if (! $store->is_live) {
            throw ApiException::notFound();
        }

        $payload = $this->cache->rememberStore($store->id, 'profile', function () use ($store, $request): array {
            // Eager loaded together, because rendering the listing rows touches the
            // variant and the product for every attachment and would otherwise be an
            // N+1.
            $store->load(['attachments.variant', 'attachments.product']);

            return (new StoreResource($store))->response($request)->getData(true);
        });

        return response()->json($payload);
    }
}
