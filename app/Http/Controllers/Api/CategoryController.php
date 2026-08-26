<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The category list (EP-53).
 */
final class CategoryController extends Controller
{
    /**
     * EP-53 Distinct categories with a product count.
     *
     * Category is a free string column with no lookup table, so the list has to be
     * derived from the data. Hardcoding it in the client would let it drift from what
     * the catalogue actually holds.
     *
     * Cached for an hour: it changes only when a product is created in a category that
     * had none, which is rare, and it is read on the two busiest public pages.
     */
    public function index(): JsonResponse
    {
        $categories = Cache::remember('catalogue.categories', now()->addHour(), fn () => DB::table('products')
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
