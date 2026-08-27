<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a product into the search index.
 *
 * Dispatched by hand after the wizard transaction commits, rather than left to Scout's
 * own model observer. The observer fires on save, which is inside the transaction, and
 * a product indexed there would be advertised by search before the row was committed
 * and would stay in the index if the transaction then rolled back.
 *
 * Being a job rather than an inline call matters for a second reason: the search engine
 * is an external service, and a slow or unreachable one must not fail the request that
 * just created a product. The product exists either way, and an unindexed product is
 * still reachable by its own page and its category.
 */
final class IndexProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $productId) {}

    public function handle(): void
    {
        // Fetched rather than passed as a model, so the job always indexes the current
        // state. A serialised model would carry whatever the record looked like at
        // dispatch, which is not what search should be answering from.
        $product = Product::find($this->productId);

        $product?->searchable();
    }
}
