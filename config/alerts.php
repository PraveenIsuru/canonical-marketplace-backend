<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Nearby availability radius
    |--------------------------------------------------------------------------
    |
    | How close a store must be to a buyer before listing a wishlisted variant is
    | worth an email, in kilometres.
    |
    | Configurable rather than a constant because the right figure is a question
    | about geography, not about code: 25 km covers a city and its suburbs, which
    | suits the cities this platform seeds, and would be far too small in a country
    | where the nearest shop is routinely an hour away.
    |
    */

    'nearby_radius_km' => (float) env('ALERT_NEARBY_RADIUS_KM', 25),

];
