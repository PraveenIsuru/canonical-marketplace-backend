<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * How many people looked at what this seller carries (EP-39).
 *
 * Everything here counts UTC days, matching section 5 of the contract. A seller in
 * Colombo reading a chart of UTC days will see an evening's traffic land on the next
 * day's bar, which is a real cost of a single fixed reckoning, and it is still better
 * than two clients disagreeing about where a day begins.
 *
 * Three aggregates over `product_views`, which is the table that grows fastest in the
 * system. Both indexes it carries are used: `(store_id, viewed_at)` for what reached
 * this store, `(product_id, viewed_at)` for the comparison against all views of the
 * same products.
 */
final class StoreAnalyticsQuery
{
    public function forStore(Store $store, CarbonImmutable $from, CarbonImmutable $to): StoreAnalytics
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();

        $carried = $this->carriedProductIds($store);
        $scope = $this->scope($store, $carried, $start, $end);

        $products = $this->breakdown($store, $scope, $carried, $start, $end);

        return new StoreAnalytics(
            from: $from->toDateString(),
            to: $to->toDateString(),
            /*
             * Summed from the rows rather than counted separately, so the totals and
             * the breakdown can never disagree. A total that did not equal its own
             * parts would be read as a bug in the screen rather than in the query.
             */
            storeViews: array_sum(array_column($products, 'store_views')),
            productViews: array_sum(array_column($products, 'product_views')),
            daily: $this->daily($store, $scope, $from, $to, $start, $end),
            products: $products,
        );
    }

    /**
     * The products this analytics answers about.
     *
     * Currently carried products **and** products this store has views for in the
     * range. The second half matters: a seller who detached last week still earned
     * those views, and dropping them would make the total shrink retrospectively every
     * time a listing was removed.
     *
     * @param  Collection<int, int>  $carried
     * @return Collection<int, int>
     */
    private function scope(Store $store, Collection $carried, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $viewed = ProductView::query()
            ->where('store_id', $store->id)
            ->whereBetween('viewed_at', [$start, $end])
            ->distinct()
            ->pluck('product_id');

        return $carried->merge($viewed)->unique()->values();
    }

    /** @return Collection<int, int> */
    private function carriedProductIds(Store $store): Collection
    {
        return Attachment::query()
            ->where('store_id', $store->id)
            ->distinct()
            ->pluck('product_id');
    }

    /**
     * Per product counts, ordered by what reached this seller first.
     *
     * A carried product with no views at all is kept and shows a zero, because a
     * listing quietly missing from this list reads as lost rather than as unvisited.
     *
     * @param  Collection<int, int>  $scope
     * @param  Collection<int, int>  $carried
     * @return array<int, array{id: int, slug: string, name: string, store_views: int, product_views: int, is_carried: bool}>
     */
    private function breakdown(
        Store $store,
        Collection $scope,
        Collection $carried,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        if ($scope->isEmpty()) {
            return [];
        }

        $counts = ProductView::query()
            ->selectRaw('product_id')
            ->selectRaw('SUM(CASE WHEN store_id = ? THEN 1 ELSE 0 END) AS store_views', [$store->id])
            ->selectRaw('COUNT(*) AS product_views')
            ->whereIn('product_id', $scope)
            ->whereBetween('viewed_at', [$start, $end])
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $carriedIds = $carried->all();

        $rows = Product::query()
            ->whereIn('id', $scope)
            ->orderBy('name')
            ->get(['id', 'slug', 'name'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'store_views' => (int) ($counts[$product->id]->store_views ?? 0),
                'product_views' => (int) ($counts[$product->id]->product_views ?? 0),
                'is_carried' => in_array($product->id, $carriedIds, true),
            ])
            ->all();

        usort($rows, static fn (array $a, array $b): int => [$b['store_views'], $b['product_views'], $a['name']]
            <=> [$a['store_views'], $a['product_views'], $b['name']]);

        return $rows;
    }

    /**
     * One entry per day in the range, including the empty ones.
     *
     * Zero filled here rather than on the client. A chart with holes in it is the
     * commonest way a quiet week gets mistaken for a broken endpoint, and the server
     * already knows exactly which days it was asked about.
     *
     * @param  Collection<int, int>  $scope
     * @return array<int, array{date: string, store_views: int, product_views: int}>
     */
    private function daily(
        Store $store,
        Collection $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): array {
        $counted = $scope->isEmpty()
            ? collect()
            : ProductView::query()
                ->selectRaw("to_char(viewed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day")
                ->selectRaw('SUM(CASE WHEN store_id = ? THEN 1 ELSE 0 END) AS store_views', [$store->id])
                ->selectRaw('COUNT(*) AS product_views')
                ->whereIn('product_id', $scope)
                ->whereBetween('viewed_at', [$start, $end])
                ->groupByRaw("to_char(viewed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD')")
                ->get()
                ->keyBy('day');

        $series = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();

            $series[] = [
                'date' => $date,
                'store_views' => (int) ($counted[$date]->store_views ?? 0),
                'product_views' => (int) ($counted[$date]->product_views ?? 0),
            ];
        }

        return $series;
    }
}
