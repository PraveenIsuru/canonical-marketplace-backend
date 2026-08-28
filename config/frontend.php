<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Frontend base URL
    |--------------------------------------------------------------------------
    |
    | Where the Next.js client is served from. Already used to build the links in
    | outgoing email, and reused here as the host the revalidation webhook is called
    | on, so there is one answer to "where is the client" rather than two that can
    | drift apart in a deployment.
    |
    */

    'url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    'revalidation' => [

        /*
        |----------------------------------------------------------------------
        | Enabled
        |----------------------------------------------------------------------
        |
        | Turns EP-51 off without removing the dispatch. A deployment with no client
        | in front of it, and the test suite, both want version creation to work
        | without a webhook attached to it.
        |
        | Off in testing by default. A test that cares about the webhook turns it on
        | and asserts, rather than every unrelated test paying for an HTTP call.
        |
        */

        'enabled' => (bool) env('REVALIDATE_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Webhook path
        |----------------------------------------------------------------------
        |
        | The route handler the client hosts. Kept here rather than hard coded in the
        | job, because the two repositories have to agree on it and a value in
        | configuration is easier to check against the other side than a string
        | buried in a class.
        |
        */

        'path' => (string) env('REVALIDATE_PATH', '/api/revalidate'),

        /*
        |----------------------------------------------------------------------
        | Shared secret
        |----------------------------------------------------------------------
        |
        | Sent as the `x-revalidate-secret` header and compared against the client's
        | REVALIDATE_SECRET. A shared secret rather than a token because the caller is
        | a server, not a user, so there is no session to carry and nobody to log in.
        |
        | The two sides must hold the same value. A mismatch is a 401 from the client
        | and a failed job here, which is the correct outcome: a wrong secret must not
        | be able to make the client rebuild pages.
        |
        */

        'secret' => env('REVALIDATE_SECRET'),

        /*
        |----------------------------------------------------------------------
        | Request timeout, in seconds
        |----------------------------------------------------------------------
        |
        | Short on purpose. This call happens after the version is already committed,
        | so nothing is waiting on it, and a client that has not answered in five
        | seconds is better retried later than held open.
        |
        */

        'timeout' => (int) env('REVALIDATE_TIMEOUT_SECONDS', 5),

    ],

];
