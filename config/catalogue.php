<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Catalogue caching
    |--------------------------------------------------------------------------
    |
    | The public catalogue is the only part of the platform where the same bytes are
    | served to everybody, over and over, from queries that count attachments across
    | the largest table in the system. That combination is what makes it worth caching
    | and makes almost nothing else here worth caching.
    |
    | Off switches the whole layer out. Every read still answers correctly, just from
    | the database each time, which is what the first eleven milestones did.
    |
    */

    'cache' => [

        'enabled' => (bool) env('CATALOGUE_CACHE_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Store
        |----------------------------------------------------------------------
        |
        | Null means the application default. Naming a store here is what lets the
        | catalogue live in Redis while sessions and everything else stay wherever
        | they are, which is the arrangement this is written for.
        |
        | Nothing in this layer uses cache tags. Tags are a Redis and Memcached
        | feature, and depending on them would mean the catalogue cache silently
        | stopped invalidating on any deployment that fell back to the database
        | store. Invalidation is done with generation counters instead, which behave
        | identically on every driver.
        |
        */

        'store' => env('CATALOGUE_CACHE_STORE'),

        /*
        |----------------------------------------------------------------------
        | Time to live, in seconds
        |----------------------------------------------------------------------
        |
        | A backstop, not the mechanism. Entries are invalidated by the events that
        | make them wrong, so a long life is safe. What the TTL actually buys is a
        | ceiling on how long a mistake can last: if some future write path forgets
        | to invalidate, the wrong answer expires within the hour instead of being
        | served until the cache is cleared by hand.
        |
        | The catalogue listing gets a much shorter one. It aggregates prices across
        | every product, so it is the entry most exposed to a missed invalidation and
        | the least expensive to rebuild.
        |
        */

        'ttl' => (int) env('CATALOGUE_CACHE_TTL', 3600),

        'listing_ttl' => (int) env('CATALOGUE_CACHE_LISTING_TTL', 300),

    ],

];
