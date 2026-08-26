<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    RateLimitServiceProvider::class,
    AiServiceProvider::class,
];
