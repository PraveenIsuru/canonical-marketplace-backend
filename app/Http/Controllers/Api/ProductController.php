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
use App\Services\Catalogue\CatalogueCache;
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
 *
 * ## What is cached, and what is not
 *
 * Four of these five read the same answer for everybody, so they are served through
 * [CatalogueCache] and invalidated by the writes that make them wrong rather than by a
 * timer. What is cached is the **finished response body**, not the query result. The
 * expensive part of these endpoints is counting attachments, but the serialisation is
 * not free either, and caching after it means a hit does no work at all.
 *
 * The seller list is the exception and is never cached, for the reason given on the
 * action itself.
 */
final class ProductController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly CatalogueCache $cache) {}

    /**
     * EP-08 Catalogue listing.
     *
     * The price and seller count come from correlated subqueries rather than a join
     * with grouping. A join would multiply product rows by attachment rows before
     * collapsing them, which makes pagination counts wrong.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 24), self::MAX_PER_PAGE);
        $category = $validated['category'] ?? null;

        /*
         * The page number is part of the key as well as the filters. Page two of the
         * phones category is a different answer from page one of everything, and a key
         * that ignored it would serve the first page under every page number.
         */
        $payload = $this->cache->rememberCatalogue(
            'products',
            [
                'category' => $category,
                'per_page' => $perPage,
                'page' => (int) $request->integer('page', 1),
            ],
            function () use ($category, $perPage, $request): array {
                $products = Product::query()
                    ->with('images')
                    ->when(
                        $category !== null,
                        fn ($query) => $query->where('category', $category),
                    )
                    ->select('products.*')
                    ->selectSub($this->lowestPriceSubquery(), 'lowest_price_minor')
                    ->selectSub($this->sellerCountSubquery(), 'seller_count')
                    ->orderByDesc('products.created_at')
                    ->paginate($perPage);

                return ProductSummaryResource::collection($products)->response($request)->getData(true);
            },
        );

        return response()->json($payload);
    }

    /**
     * EP-09 The canonical record.
     *
     * Resolved by slug through route model binding. This is the payload the static page
     * is built from, so it holds nothing volatile.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $payload = $this->cache->rememberProduct($product, 'record', function () use ($product, $request): array {
            $product->loadMissing(['images', 'productAttributes', 'currentVersion']);
            $product->setAttribute('seller_count', $this->sellerCountFor($product));

            return (new ProductResource($product))->response($request)->getData(true);
        });

        return response()->json($payload);
    }

    /**
     * EP-10 Every generated combination.
     *
     * Combinations no seller carries are included. Filtering them out here would
     * silently reintroduce variant removal, which nothing in the system permits.
     */
    public function variants(Request $request, Product $product): JsonResponse
    {
        $payload = $this->cache->rememberProduct($product, 'variants', function () use ($product, $request): array {
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

            return VariantResource::collection($variants)->response($request)->getData(true);
        });

        return response()->json($payload);
    }

    /**
     * EP-11 The seller list. The most performance sensitive endpoint in the system.
     *
     * Not cached, because the ordering depends on the buyer's coordinates. Two buyers
     * in different cities ask the same question and must get different answers, so a
     * shared entry would either be wrong for one of them or would need a key per pair
     * of coordinates, which is a cache that never gets a hit.
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
        $payload = $this->cache->rememberProduct($product, 'summary', function () use ($product): array {
            $summary = $product->summary;

            return [
                'data' => $summary === null ? null : [
                    'summary' => $summary->summary_text,
                    'generated_at' => $summary->generated_at->toIso8601String(),
                ],
            ];
        });

        return response()->json($payload);
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
