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
    | Supported: "fake", "anthropic", "gemini"
    |
    | Switching is a config change and nothing else: clear the config cache and restart
    | the queue workers, which hold the old value in memory until they do.
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

    /*
    |--------------------------------------------------------------------------
    | Confidence band threshold
    |--------------------------------------------------------------------------
    |
    | A proposal's confidence score at or above this counts as "high", and below it
    | as "low". The band is what the resolution matrix reads: high confidence with
    | peers against escalates to an administrator, low confidence with peers against
    | is rejected outright.
    |
    | The raw score is stored alongside the band precisely so this number can be
    | retuned later without the past meaning something different than it did.
    |
    | Neither the score nor the band ever appears in a response body.
    |
    */

    'confidence_high_threshold' => (float) env('AI_CONFIDENCE_HIGH_THRESHOLD', 0.7),

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini
    |--------------------------------------------------------------------------
    |
    | Two of the seven calls send an image, so whatever model is named here has to be
    | vision capable. The rest are short JSON answers on the interactive path, where
    | latency is the cost that shows, which is why the default is a Flash Lite rather
    | than the largest model available.
    |
    | Changing this line is safe, but read GeminiTransport first. Models differ in how
    | much they think before answering and the transport deliberately leaves that at
    | each model's own default, because the newest Flash rejects outright the setting
    | the Flash Lite models default to. A model that thinks more spends more of the
    | reply budget doing it.
    |
    | The timeout is shared with the other provider on purpose. It describes how long
    | the platform is willing to wait, not how slow a particular vendor is, and a
    | per vendor knob would be an invitation to raise it rather than to degrade.
    |
    */

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 5),
    ],

];
