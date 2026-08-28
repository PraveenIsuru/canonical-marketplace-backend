<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\Product;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue read cache, and the one place that decides when it is wrong.
 *
 * Only the public catalogue is cached. Those responses are identical for every
 * visitor, are read far more often than the records behind them change, and are built
 * from correlated counts over the attachments table, which is the largest in the
 * system. Nothing else in the platform has all three properties, which is why nothing
 * else is cached.
 *
 * **The seller list is deliberately absent.** Its ordering depends on the buyer's
 * coordinates, so every visitor would need their own entry and the cache would hold a
 * near endless number of near identical answers. It is read straight from the
 * database, as it always has been.
 *
 * ## Why generation counters rather than cache tags
 *
 * Laravel's tags are supported by Redis and Memcached and not by the database or file
 * stores. A tagged implementation would work in production and quietly stop
 * invalidating anywhere Redis was not configured, and the symptom would be a product
 * page serving last week's specifications with nothing in any log to say why.
 *
 * A generation counter is a number that forms part of every key derived from a record.
 * Invalidating means writing a new number, after which every key built from the old one
 * is simply never asked for again and expires on its own. It needs nothing from the
 * driver beyond get and put, so it behaves the same on the database store used in
 * development and on Redis in production. That equivalence is the point: the cache that
 * is tested locally is the cache that runs.
 *
 * Generations are microsecond stamps rather than an incrementing count. A counter that
 * is evicted restarts at zero and starts handing out namespaces that already hold
 * entries, which would serve genuinely stale data. A stamp that is evicted produces a
 * number larger than every one before it, so a lost generation costs a rebuild and can
 * never resurrect an old answer.
 */
final class CatalogueCache
{
    private const PREFIX = 'catalogue';

    /**
     * Reads a product scoped payload, computing it only when it is not already held.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function rememberProduct(Product $product, string $facet, Closure $callback): mixed
    {
        return $this->remember(
            $this->productKey($product->id, $facet),
            (int) config('catalogue.cache.ttl'),
            $callback,
        );
    }

    /**
     * Reads a store scoped payload.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function rememberStore(int $storeId, string $facet, Closure $callback): mixed
    {
        return $this->remember(
            $this->storeKey($storeId, $facet),
            (int) config('catalogue.cache.ttl'),
            $callback,
        );
    }

    /**
     * Reads a catalogue wide payload, such as the paginated listing or the categories.
     *
     * The parameters are part of the key, because page two of the phones category is a
     * different answer from page one of everything.
     *
     * @template TValue
     *
     * @param  array<string, mixed>  $parameters
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function rememberCatalogue(string $facet, array $parameters, Closure $callback): mixed
    {
        ksort($parameters);

        $key = sprintf(
            '%s:list:g%d:%s:%s',
            self::PREFIX,
            $this->generation(self::PREFIX.':list:generation'),
            $facet,
            md5((string) json_encode($parameters)),
        );

        return $this->remember($key, (int) config('catalogue.cache.listing_ttl'), $callback);
    }

    /**
     * One product's cached reads are wrong.
     *
     * Called when the record itself changed, and when the attachments beneath it did,
     * because the product page carries a seller count and the variant list carries a
     * lowest price. The catalogue listing goes with it: it shows the same figures for
     * this product in a row of its own.
     */
    public function forgetProduct(Product|int $product): void
    {
        $id = $product instanceof Product ? $product->id : $product;

        $this->bump($this->productGenerationKey($id));
        $this->forgetCatalogue();
    }

    /**
     * One store's cached reads are wrong.
     *
     * When the change was the live flag flipping, every product the store carries is
     * wrong too, because each of their seller counts just moved. Those products are
     * looked up rather than guessed: a store going dark is rare enough that the query
     * costs nothing, and guessing would leave product pages advertising a shop that no
     * longer appears on them.
     */
    public function forgetStore(int $storeId, bool $withCarriedProducts = false): void
    {
        $this->bump($this->storeGenerationKey($storeId));

        if ($withCarriedProducts) {
            $productIds = DB::table('attachments')
                ->where('store_id', $storeId)
                ->distinct()
                ->pluck('product_id');

            foreach ($productIds as $productId) {
                $this->bump($this->productGenerationKey((int) $productId));
            }
        }

        $this->forgetCatalogue();
    }

    /**
     * Every catalogue wide payload is wrong.
     *
     * Coarse on purpose. The listing aggregates a lowest price and a seller count
     * across products, so a change to any one product makes some page of it wrong, and
     * working out which page would cost more than rebuilding all of them. The listing
     * TTL is short for the same reason.
     */
    public function forgetCatalogue(): void
    {
        $this->bump(self::PREFIX.':list:generation');
    }

    /**
     * Reads through the cache, or straight past it when the layer is switched off.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private function remember(string $key, int $ttl, Closure $callback): mixed
    {
        if (config('catalogue.cache.enabled') !== true) {
            return $callback();
        }

        return $this->store()->remember($key, $ttl, $callback);
    }

    private function productKey(int $productId, string $facet): string
    {
        return sprintf(
            '%s:product:%d:g%d:%s',
            self::PREFIX,
            $productId,
            $this->generation($this->productGenerationKey($productId)),
            $facet,
        );
    }

    private function storeKey(int $storeId, string $facet): string
    {
        return sprintf(
            '%s:store:%d:g%d:%s',
            self::PREFIX,
            $storeId,
            $this->generation($this->storeGenerationKey($storeId)),
            $facet,
        );
    }

    private function productGenerationKey(int $productId): string
    {
        return self::PREFIX.':product:'.$productId.':generation';
    }

    private function storeGenerationKey(int $storeId): string
    {
        return self::PREFIX.':store:'.$storeId.':generation';
    }

    /**
     * The current generation for a key, seeding one where none is held.
     *
     * A missing generation is written back rather than defaulted, so an evicted counter
     * becomes a fresh namespace instead of an old one that still holds entries.
     */
    private function generation(string $key): int
    {
        $held = $this->store()->get($key);

        if (is_int($held)) {
            return $held;
        }

        $stamp = $this->stamp();
        $this->store()->forever($key, $stamp);

        return $stamp;
    }

    /** Moves a generation forward, which abandons every key built from the old one. */
    private function bump(string $key): void
    {
        if (config('catalogue.cache.enabled') !== true) {
            return;
        }

        $this->store()->forever($key, $this->stamp());
    }

    /**
     * Microseconds since the epoch.
     *
     * Fine grained enough that two invalidations in the same request produce different
     * numbers, and always increasing, which is what makes an evicted generation safe.
     */
    private function stamp(): int
    {
        return (int) (microtime(true) * 1_000_000);
    }

    private function store(): Repository
    {
        $store = config('catalogue.cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
