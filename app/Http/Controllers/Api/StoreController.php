<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;

/**
 * The public store profile (EP-13).
 */
final class StoreController extends Controller
{
    /**
     * EP-13 One store, with what it carries.
     *
     * A dark store answers 404 rather than returning an empty profile. It is not
     * visible to buyers, so it must not be reachable by guessing an id either.
     */
    public function show(Store $store): StoreResource
    {
        if (! $store->is_live) {
            throw ApiException::notFound();
        }

        // Eager loaded together, because rendering the listing rows touches the variant
        // and the product for every attachment and would otherwise be an N+1.
        $store->load(['attachments.variant', 'attachments.product']);

        return new StoreResource($store);
    }
}
