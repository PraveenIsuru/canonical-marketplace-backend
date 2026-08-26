<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named rate limiters.
 *
 * Defined in one place rather than scattered across route definitions, so the whole
 * policy can be read at once and matched against section 9 of the API contract.
 *
 * Exceeding a limiter returns 429 with the standard envelope and a Retry-After
 * header, which the exception renderer handles.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * These are prefixed `api-` deliberately. FortifyServiceProvider already owns
         * the names `login`, `two-factor`, and `passkeys` for the starter kit's own
         * web auth, and registering the same names here would silently replace its
         * limiters with ones keyed differently. The platform API is a separate surface
         * with its own throttles.
         */

        // Credentials endpoints. Per IP, because the attacker is not authenticated.
        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('api-password', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('api-register', fn (Request $request) => Limit::perHour(3)->by($request->ip()));

        // The public catalogue is the highest traffic surface. Generous, but not open.
        RateLimiter::for('catalogue', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Search costs an AI call on the happy path, so it is tighter than the catalogue.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        /*
         * Attachment flows. The quota protects the store, but one user holds exactly
         * one store, so the user id is an equivalent and stable key, and it works
         * before the store relation exists.
         */
        RateLimiter::for('attach', fn (Request $request) => Limit::perHour(20)->by(self::actorKey($request)));

        /*
         * Verification submissions. The limit deliberately matches the five attempt
         * ceiling, but it is a second line of defence only. The real ceiling is
         * enforced against persisted attempts, because a rate limiter resets with time
         * and the attempt allowance must not.
         */
        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(5)->by(self::actorKey($request)));

        // Everything authenticated that writes.
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by(self::actorKey($request)));
    }

    /**
     * The key an authenticated limiter counts against.
     *
     * Falls back to the IP so an unauthenticated request hitting one of these routes
     * is still limited rather than sharing an empty bucket with every other caller.
     */
    private static function actorKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null ? (string) $user->getAuthIdentifier() : (string) $request->ip();
    }
}
