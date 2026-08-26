<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The seller list for a product page.
 *
 * The single most performance sensitive query in the system. It runs on the highest
 * traffic page, for anonymous and authenticated visitors alike, and it cannot be
 * cached per request because the ordering depends on where the buyer is standing.
 *
 * Two decisions shape it:
 *
 * Distance is computed in PostGIS, never in PHP. Sorting in PHP would mean loading
 * every attachment for the product into memory first, which does not scale with
 * attachment count and makes database level filtering impossible.
 *
 * It filters on the denormalised attachments.product_id rather than joining through
 * variants. The column is redundant, and that redundancy is the point: it removes a
 * join from the most requested query in the system.
 */
final class SellerListQuery
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly Product $product,
        private readonly SellerListFilters $filters,
    ) {}

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function paginate(): LengthAwarePaginator
    {
        $hasCoordinates = $this->filters->hasCoordinates();

        $query = DB::table('attachments')
            ->join('stores', 'stores.id', '=', 'attachments.store_id')
            ->join('variants', 'variants.id', '=', 'attachments.variant_id')
            // The denormalised column. No join to variants is needed for this filter.
            ->where('attachments.product_id', $this->product->id)
            // Dark stores are invisible to buyers, everywhere, always.
            ->where('stores.is_live', true)
            ->whereNull('stores.deleted_at')
            ->select([
                'attachments.id',
                'attachments.variant_id',
                'attachments.price_minor',
                'attachments.currency',
                'attachments.is_available',
                'variants.attribute_values',
                'stores.id as store_id',
                'stores.name as store_name',
                'stores.category as store_category',
                'stores.contact_email',
                'stores.contact_phone',
                'stores.address_line',
                'stores.city',
                'stores.latitude',
                'stores.longitude',
                'stores.rating',
            ]);

        $this->applyDistance($query, $hasCoordinates);
        $this->applyFilters($query, $hasCoordinates);
        $this->applySort($query, $hasCoordinates);

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * Adds distance_km, or a literal null when the buyer gave no location.
     *
     * Null rather than zero matters: the client renders nothing for null, where zero
     * would read as "next door".
     */
    private function applyDistance(QueryBuilder $query, bool $hasCoordinates): void
    {
        if (! $hasCoordinates) {
            $query->selectRaw('NULL::float AS distance_km');

            return;
        }

        $query->selectRaw(
            'ST_Distance(stores.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000 AS distance_km',
            // Longitude first. Reversing this is the classic geospatial bug.
            [$this->filters->longitude, $this->filters->latitude],
        );
    }

    private function applyFilters(QueryBuilder $query, bool $hasCoordinates): void
    {
        if ($this->filters->variantId !== null) {
            $query->where('attachments.variant_id', $this->filters->variantId);
        }

        if ($this->filters->maxPriceMinor !== null) {
            $query->where('attachments.price_minor', '<=', $this->filters->maxPriceMinor);
        }

        if ($this->filters->minRating !== null) {
            $query->where('stores.rating', '>=', $this->filters->minRating);
        }

        if ($this->filters->availableOnly) {
            $query->where('attachments.is_available', true);
        }

        /*
         * A distance filter without coordinates is meaningless rather than empty, so it
         * is ignored instead of excluding everything. A buyer who has not shared a
         * location should still see sellers.
         */
        if ($this->filters->maxDistanceKm !== null && $hasCoordinates) {
            $query->whereRaw(
                'ST_DWithin(stores.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$this->filters->longitude, $this->filters->latitude, $this->filters->maxDistanceKm * 1000],
            );
        }
    }

    /**
     * Distance by default when a location is known, price otherwise.
     *
     * Sorting by a distance that is null for every row would be an arbitrary ordering
     * presented as a meaningful one.
     */
    private function applySort(QueryBuilder $query, bool $hasCoordinates): void
    {
        $sort = $this->filters->sort ?? ($hasCoordinates ? 'distance' : 'price');

        match ($sort) {
            'distance' => $hasCoordinates
                ? $query->orderBy('distance_km')
                : $query->orderBy('attachments.price_minor'),
            'rating' => $query->orderByDesc('stores.rating'),
            default => $query->orderBy('attachments.price_minor'),
        };

        // A stable tiebreak, so pagination cannot repeat or drop a row between pages.
        $query->orderBy('attachments.id');
    }
}
