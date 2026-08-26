<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Which adapter backs the AiProvider interface. `fake` needs no credentials and
    | no network, which is what lets the platform be developed and tested without a
    | provider bill.
    |
    | Supported: "fake", "anthropic"
    |
    */

    'provider' => env('AI_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Fake provider failure switch
    |--------------------------------------------------------------------------
    |
    | Forces the fake adapter to fail every call. This is how the two opposite
    | failure behaviours are demonstrated: buyer search degrades to keyword results
    | and returns 200, while seller catalogue search queues the work and returns 503.
    |
    | Set AI_FAKE_SHOULD_FAIL=true in .env to see it in a browser.
    |
    */

    'fake_should_fail' => (bool) env('AI_FAKE_SHOULD_FAIL', false),

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 5),
    ],

];
