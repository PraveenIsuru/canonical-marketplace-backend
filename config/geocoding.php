<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Which adapter backs the GeocodingProvider interface. `fake` needs no credentials
    | and no network, and resolves a handful of known cities.
    |
    | Supported: "fake", "locationiq"
    |
    */

    'provider' => env('GEOCODING_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Fake provider failure switch
    |--------------------------------------------------------------------------
    |
    | Forces every geocoding attempt to fail. This is how the manual pin path is
    | demonstrated: store creation still returns 201 with null coordinates and
    | geocoding_failed true, which routes the seller into pin placement.
    |
    | Set GEOCODING_FAKE_SHOULD_FAIL=true in .env to see it in a browser.
    |
    */

    'fake_should_fail' => (bool) env('GEOCODING_FAKE_SHOULD_FAIL', false),

    'locationiq' => [
        'key' => env('LOCATIONIQ_API_KEY'),
        'timeout' => (int) env('GEOCODING_TIMEOUT_SECONDS', 5),
    ],

];
