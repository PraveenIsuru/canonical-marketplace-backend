<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Attachment;
use App\Models\Proposal;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * What a seller currently carries, and what they are blocked on (EP-19).
 *
 * Two halves in one call, and the second half is the interesting one. A product with a
 * proposal under review has **no attachment row at all**, so a listings screen built
 * only from attachments would show nothing and leave the seller wondering where their
 * submission went. The blocked entries are what let the screen say "this is waiting on
 * other sellers" instead.
 *
 * Fetching both here rather than making the client call twice also keeps the two
 * consistent: a proposal resolving between two requests would otherwise show a product
 * as neither listed nor blocked.
 */
final class StoreListingsQuery
{
    /**
     * @return array{listings: Collection<int, StoreListing>, blocked: Collection<int, Proposal>}
     */
    public function forStore(Store $store): array
    {
        return [
            'listings' => $this->listings($store),
            'blocked' => $this->blocked($store),
        ];
    }

    /**
     * Attachments grouped by product.
     *
     * Grouped rather than returned flat because a seller thinks in products, not in
     * variants: "the ceramic jug, in two sizes" rather than two unrelated rows that
     * happen to share a name.
     *
     * @return Collection<int, StoreListing>
     */
    private function listings(Store $store): Collection
    {
        $attachments = Attachment::query()
            ->where('store_id', $store->id)
            // Eager loaded because the screen renders a product name and a primary
            // image for every row, which is otherwise a query per listing.
            ->with(['product.images', 'variant'])
            ->orderBy('product_id')
            ->orderBy('variant_id')
            ->get();

        return $attachments
            ->groupBy('product_id')
            ->map(function (Collection $forProduct): StoreListing {
                /** @var Attachment $first */
                $first = $forProduct->first();

                return new StoreListing($first->product, $forProduct);
            })
            ->values();
    }

    /**
     * Proposals that are stopping this seller from listing something.
     *
     * Pending and escalated both count. An escalated proposal ran out of window without
     * enough votes and is waiting on an administrator, so the seller is still blocked
     * and still owed an answer. Treating it as finished would be the crueller mistake:
     * they would be told nothing is happening while their case sits in a queue.
     *
     * @return Collection<int, Proposal>
     */
    private function blocked(Store $store): Collection
    {
        return Proposal::query()
            ->blocking()
            ->where('store_id', $store->id)
            ->with('product')
            ->orderBy('review_closes_at')
            ->get();
    }
}
