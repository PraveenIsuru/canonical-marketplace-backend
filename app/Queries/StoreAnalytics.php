<?php

declare(strict_types=1);

namespace App\Queries;

/**
 * A seller's view counts over one date range (EP-39), per section 11.11.
 *
 * Two totals rather than one, and the pair is the whole point. `storeViews` is what
 * reached this seller; `productViews` is all the interest in the same products,
 * whoever it reached. A number on its own would tell a seller nothing about whether
 * forty views is good.
 */
final readonly class StoreAnalytics
{
    /**
     * @param  array<int, array{date: string, store_views: int, product_views: int}>  $daily
     * @param  array<int, array{id: int, slug: string, name: string, store_views: int, product_views: int, is_carried: bool}>  $products
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $storeViews,
        public int $productViews,
        public array $daily,
        public array $products,
    ) {}
}
