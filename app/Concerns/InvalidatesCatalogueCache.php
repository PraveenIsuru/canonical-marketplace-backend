<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Services\Catalogue\CatalogueCache;

/**
 * Lets a model say that a catalogue read is now wrong.
 *
 * Invalidation hangs off the models rather than off the services that write them, for
 * the same reason store visibility does: a future code path that writes an attachment
 * by some route nobody has thought of yet still invalidates, without whoever wrote it
 * having to know this layer exists. A cache that depends on every caller remembering
 * is a cache that serves stale data the first time somebody forgets.
 *
 * Resolved from the container each time rather than held, because a model is
 * serialised into queue payloads and a service handle has no business travelling with
 * it.
 */
trait InvalidatesCatalogueCache
{
    protected static function catalogueCache(): CatalogueCache
    {
        return app(CatalogueCache::class);
    }
}
