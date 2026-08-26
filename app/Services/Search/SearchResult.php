<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A page of search results, carrying the mode that produced it.
 *
 * The mode travels with the results rather than being decided by the caller, so a
 * response cannot claim one path served it while another actually did.
 */
final readonly class SearchResult
{
    /**
     * @param  LengthAwarePaginator<int, Product>  $results
     */
    public function __construct(
        public SearchMode $mode,
        public LengthAwarePaginator $results,
    ) {}
}
