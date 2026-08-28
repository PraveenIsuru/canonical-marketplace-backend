<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use App\Queries\VersionEntry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Reading a product's version chain (EP-46, EP-47).
 *
 * One class holds both the access rule and the reads, for the same reason the
 * resolution matrix lives in one service: the rule is the substance here, and two
 * copies of it would eventually disagree about who may read a history. The reads
 * themselves are almost trivial.
 *
 * **Access is re-read on every request.** Nothing is cached, nothing is carried in a
 * token, and nothing is decided at login. A seller who detaches from a product loses
 * the history on their very next request, mid session, which is the behaviour the
 * build plan asks for and would be impossible if this were answered from anything but
 * the attachments table as it stands right now.
 *
 * Rejected proposals need no filtering here and get none. A version row exists for an
 * accepted proposal and an administrator edit and for nothing else, so a rejected
 * proposal is absent because it was never written rather than because it was hidden.
 */
final class VersionHistoryService
{
    /**
     * Refuses anybody who may not read this product's history.
     *
     * The order of the two refusals matters. A caller with no store at all is told
     * `store_required`, which is the registered meaning of holding no store, and a
     * caller who holds one is told `not_attached`, which says the specific thing that
     * is wrong. Collapsing them into one code would leave a seller unable to tell "you
     * need a shop" apart from "you do not stock this".
     *
     * **Holding the seller role is not enough.** A seller carrying forty other
     * products is refused on the one they do not carry, because the history is a
     * working document for the sellers responsible for a record rather than a
     * catalogue wide privilege.
     */
    public function assertReadable(?User $user, Product $product): void
    {
        if ($user === null) {
            throw ApiException::forbidden();
        }

        // An administrator reads every history, with or without a store of their own,
        // which is why these endpoints are not behind the seller middleware.
        if ($user->is_admin) {
            return;
        }

        $store = $user->store;

        if ($store === null) {
            throw ApiException::storeRequired();
        }

        $carriesIt = Attachment::query()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->exists();

        if (! $carriesIt) {
            throw ApiException::notAttached();
        }
    }

    /**
     * The chain, newest first.
     *
     * @return LengthAwarePaginator<int, VersionEntry>
     */
    public function list(Product $product, int $perPage): LengthAwarePaginator
    {
        $versions = ProductVersion::query()
            ->where('product_id', $product->id)
            ->with('causedByStore')
            ->orderByDesc('version_number')
            ->paginate($perPage);

        $previous = $this->previousSnapshots($product, collect($versions->items()));

        return $versions->through(
            fn (ProductVersion $version): VersionEntry => new VersionEntry(
                $version,
                $this->changedFields($version, $previous[$version->version_number - 1] ?? null),
            ),
        );
    }

    /** One version, or null when the product has no such version number. */
    public function find(Product $product, int $versionNumber): ?VersionEntry
    {
        $version = ProductVersion::query()
            ->where('product_id', $product->id)
            ->where('version_number', $versionNumber)
            ->with('causedByStore')
            ->first();

        if ($version === null) {
            return null;
        }

        $previous = ProductVersion::query()
            ->where('product_id', $product->id)
            ->where('version_number', $versionNumber - 1)
            ->first();

        return new VersionEntry($version, $this->changedFields($version, $previous));
    }

    /**
     * The versions immediately preceding the page being displayed.
     *
     * One extra query for the whole page rather than one per row. A page of twenty
     * versions needs twenty predecessors, and fetching them individually would be the
     * classic per row query that only shows up as a problem on a product with a long
     * history.
     *
     * @param  Collection<int, ProductVersion>  $page
     * @return array<int, ProductVersion>
     */
    private function previousSnapshots(Product $product, Collection $page): array
    {
        $wanted = $page
            ->map(static fn (ProductVersion $version): int => $version->version_number - 1)
            ->filter(static fn (int $number): bool => $number >= 1)
            ->unique()
            ->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        return ProductVersion::query()
            ->where('product_id', $product->id)
            ->whereIn('version_number', $wanted)
            ->get()
            ->keyBy('version_number')
            ->all();
    }

    /**
     * Which top level parts of the snapshot differ from the version before.
     *
     * Coarse on purpose. It names `attributes` rather than describing which option was
     * added to which attribute, because a snapshot is a whole record state and a
     * truthful field level diff of nested attribute and variant lists is a much larger
     * thing than a history list needs. The full snapshot is one request away for
     * anybody who wants the detail.
     *
     * **Version 1 changed nothing.** It created the record, and there was no earlier
     * state for it to differ from, so it reports an empty array rather than listing
     * every field it happens to contain.
     *
     * @return array<int, string>
     */
    private function changedFields(ProductVersion $version, ?ProductVersion $previous): array
    {
        if ($previous === null) {
            return [];
        }

        $current = $version->snapshot;
        $before = $previous->snapshot;

        $keys = array_unique([...array_keys($current), ...array_keys($before)]);
        sort($keys);

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => ($current[$key] ?? null) != ($before[$key] ?? null),
        ));
    }
}
