<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductVersionResource;
use App\Http\Resources\ProductVersionSnapshotResource;
use App\Models\Product;
use App\Services\Catalogue\VersionHistoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A product's version chain (EP-46, EP-47).
 *
 * Registered in the Auth group rather than the Seller group, and that is deliberate.
 * The contract grants these to a **seller attached to the product, or an
 * administrator**, and the seller middleware would refuse an administrator who holds
 * no store of their own before the request ever reached this class.
 *
 * Thin. The access rule and both reads live in VersionHistoryService, in one place, so
 * the list and the detail cannot come to disagree about who may read a history.
 */
final class ProductVersionController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly VersionHistoryService $history) {}

    /**
     * EP-46 The chain, newest first.
     *
     * The access check runs before anything is read, so a caller who may not see this
     * history learns nothing about its length or whether the product has one at all.
     */
    public function index(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->history->assertReadable($request->user(), $product);

        return ProductVersionResource::collection(
            $this->history->list($product, $this->perPage($request)),
        );
    }

    /**
     * EP-47 One version, with its full snapshot.
     *
     * Same access as the list, checked again here rather than inherited from having
     * just called it. Access is a property of this request, and a seller who detached a
     * moment ago must be refused on this one even though the list they are looking at
     * was allowed.
     *
     * The refusal comes **before** the version lookup, so an unattached seller cannot
     * learn which version numbers exist by reading which ones answer 404.
     */
    public function show(Request $request, Product $product, int $version): ProductVersionSnapshotResource
    {
        $this->history->assertReadable($request->user(), $product);

        $entry = $this->history->find($product, $version)
            ?? throw ApiException::notFound('That version does not exist.');

        return new ProductVersionSnapshotResource($entry);
    }

    /** Capped at 100 by the contract, section 2. */
    private function perPage(Request $request): int
    {
        return min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', 20)));
    }
}
