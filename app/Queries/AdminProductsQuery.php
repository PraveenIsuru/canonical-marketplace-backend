<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Attachment;
use App\Models\Product;
use App\Models\Proposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reading the catalogue as an administrator (EP-60, EP-61).
 *
 * Different from the buyer catalogue query in what it counts and what it hides. A buyer
 * sees live stores and prices; an administrator sees every record including the ones no
 * seller carries, with the counts that say whether a record is healthy and whether
 * somebody is blocked on it.
 *
 * It hides exactly one thing more than the buyer query does: nothing. `created_by_store_id`
 * is absent here for the same reason it is absent everywhere, per section 6. Records are
 * platform owned and there is no reader for whom that stops being true.
 */
final class AdminProductsQuery
{
    /**
     * EP-60 Every product, newest first.
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function all(int $perPage, ?string $search = null, ?string $category = null): LengthAwarePaginator
    {
        $query = $this->base();

        if ($search !== null && $search !== '') {
            /*
             * Postgres ILIKE rather than the search index. This list is an
             * administrator finding one known record, not a buyer discovering
             * something, so relevance ranking would be the wrong tool and a stale index
             * would be actively misleading about what exists.
             */
            $query->where('name', 'ilike', '%'.$search.'%');
        }

        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /** EP-61 One product in full. */
    public function find(int $id): ?Product
    {
        return $this->base()
            ->with([
                'productAttributes',
                'images',
                // Every generated combination, including the ones nobody carries.
                // Section 11.5: omitting them would be the first place somebody got the
                // idea a combination can be removed.
                'variants',
                'currentVersion',
            ])
            ->whereKey($id)
            ->first();
    }

    /**
     * The shared select.
     *
     * `seller_count` counts distinct stores rather than attachments, because a store
     * carrying three combinations of one product is one seller, not three.
     *
     * @return Builder<Product>
     */
    private function base(): Builder
    {
        return Product::query()
            ->with('currentVersion')
            ->withCount([
                'variants',
                'images',
            ])
            ->addSelect([
                'seller_count' => Attachment::query()
                    ->selectRaw('count(distinct store_id)')
                    ->whereColumn('attachments.product_id', 'products.id'),
            ])
            ->withExists([
                /*
                 * Pending and escalated both count, because both mean a seller is
                 * blocked on this record right now and an administrator about to edit
                 * it should know that before they do.
                 */
                'proposals as has_pending_proposal' => fn ($query) => $query->whereIn(
                    'status',
                    [Proposal::STATUS_PENDING, Proposal::STATUS_ESCALATED],
                ),
            ]);
    }
}
