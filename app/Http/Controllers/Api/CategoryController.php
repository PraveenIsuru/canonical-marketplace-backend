<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Catalogue\CatalogueCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The category list (EP-53).
 */
final class CategoryController extends Controller
{
    public function __construct(private readonly CatalogueCache $cache) {}

    /**
     * EP-53 Distinct categories with a product count.
     *
     * Category is a free string column with no lookup table, so the list has to be
     * derived from the data. Hardcoding it in the client would let it drift from what
     * the catalogue actually holds.
     *
     * Cached because it changes only when a product is created in a category that had
     * none, which is rare, and it is read on the two busiest public pages.
     *
     * It moved onto the catalogue cache at M12 rather than keeping its own key. It used
     * to expire on a timer and nothing else, so a new category could be up to an hour
     * late appearing in the filter. Sharing the catalogue generation means creating a
     * product invalidates it, and the hour became a backstop instead of the mechanism.
     */
    public function index(): JsonResponse
    {
        $categories = $this->cache->rememberCatalogue('categories', [], fn () => DB::table('products')
            ->select('category')
            ->selectRaw('COUNT(*) as product_count')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->category,
                'product_count' => (int) $row->product_count,
            ])
            ->all());

        return response()->json(['data' => $categories]);
    }
}
