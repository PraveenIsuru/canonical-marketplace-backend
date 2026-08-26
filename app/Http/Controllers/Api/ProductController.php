<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductSummaryResource;
use App\Http\Resources\SellerListingResource;
use App\Http\Resources\VariantResource;
use App\Models\Product;
use App\Queries\SellerListFilters;
use App\Queries\SellerListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The public catalogue (EP-08 to EP-12).
 *
 * Every action here is anonymous readable and must behave identically whether or not a
 * token happens to be present.
 */
final class ProductController extends Controller
{
    private const MAX_PER_PAGE = 100;

    /**
     * EP-08 Catalogue listing.
     *
     * The price and seller count come from correlated subqueries rather than a join
     * with grouping. A join would multiply product rows by attachment rows before
     * collapsing them, which makes pagination counts wrong.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 24), self::MAX_PER_PAGE);

        $products = Product::query()
            ->with('images')
            ->when(
                isset($validated['category']),
                fn ($query) => $query->where('category', $validated['category']),
            )
            ->select('products.*')
            ->selectSub($this->lowestPriceSubquery(), 'lowest_price_minor')
            ->selectSub($this->sellerCountSubquery(), 'seller_count')
            ->orderByDesc('products.created_at')
            ->paginate($perPage);

        return ProductSummaryResource::collection($products);
    }

    /**
     * EP-09 The canonical record.
     *
     * Resolved by slug through route model binding. This is the payload the static page
     * is built from, so it holds nothing volatile.
     */
    public function show(Product $product): ProductResource
    {
        $product->loadMissing(['images', 'productAttributes', 'currentVersion']);
        $product->setAttribute('seller_count', $this->sellerCountFor($product));

        return new ProductResource($product);
    }

    /**
     * EP-10 Every generated combination.
     *
     * Combinations no seller carries are included. Filtering them out here would
     * silently reintroduce variant removal, which nothing in the system permits.
     */
    public function variants(Product $product): AnonymousResourceCollection
    {
        $variants = $product->variants()
            ->select('variants.*')
            ->selectSub(
                DB::table('attachments')
                    ->join('stores', 'stores.id', '=', 'attachments.store_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('attachments.variant_id', 'variants.id')
                    ->where('stores.is_live', true)
                    ->whereNull('stores.deleted_at'),
                'seller_count',
            )
            ->selectSub(
                DB::table('attachments')
                    ->join('stores', 'stores.id', '=', 'attachments.store_id')
                    ->selectRaw('MIN(attachments.price_minor)')
                    ->whereColumn('attachments.variant_id', 'variants.id')
                    ->where('stores.is_live', true)
                    ->whereNull('stores.deleted_at'),
                'lowest_price_minor',
            )
            ->orderBy('id')
            ->get();

        return VariantResource::collection($variants);
    }

    /**
     * EP-11 The seller list. The most performance sensitive endpoint in the system.
     *
     * Not cached, because the ordering depends on the buyer's coordinates.
     */
    public function sellers(Request $request, Product $product): AnonymousResourceCollection
    {
        $listings = new SellerListQuery($product, SellerListFilters::fromRequest($request));

        return SellerListingResource::collection($listings->paginate());
    }

    /**
     * EP-12 The sentiment summary.
     *
     * Returns a null payload where no summary exists, so the client omits the section
     * entirely. An empty string would render as a blank panel, which looks broken.
     */
    public function summary(Product $product): JsonResponse
    {
        $summary = $product->summary;

        return response()->json([
            'data' => $summary === null ? null : [
                'summary' => $summary->summary_text,
                'generated_at' => $summary->generated_at->toIso8601String(),
            ],
        ]);
    }

    /** Lowest price across live stores only. Null where no live store carries it. */
    private function lowestPriceSubquery(): Builder
    {
        return DB::table('attachments')
            ->join('stores', 'stores.id', '=', 'attachments.store_id')
            ->selectRaw('MIN(attachments.price_minor)')
            ->whereColumn('attachments.product_id', 'products.id')
            ->where('stores.is_live', true)
            ->whereNull('stores.deleted_at');
    }

    /** Distinct stores, not attachments: one store carrying three variants counts once. */
    private function sellerCountSubquery(): Builder
    {
        return DB::table('attachments')
            ->join('stores', 'stores.id', '=', 'attachments.store_id')
            ->selectRaw('COUNT(DISTINCT attachments.store_id)')
            ->whereColumn('attachments.product_id', 'products.id')
            ->where('stores.is_live', true)
            ->whereNull('stores.deleted_at');
    }

    private function sellerCountFor(Product $product): int
    {
        return (int) DB::table('attachments')
            ->join('stores', 'stores.id', '=', 'attachments.store_id')
            ->where('attachments.product_id', $product->id)
            ->where('stores.is_live', true)
            ->whereNull('stores.deleted_at')
            ->distinct()
            ->count('attachments.store_id');
    }
}
