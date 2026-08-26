<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Seller access level.
 *
 * A user is a seller if and only if a store references them. There is no role column
 * and no roles array, so this reads the relation rather than a flag.
 */
final class EnsureUserHasStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The relation exists as of M2. A user is a seller if and only if a store
        // row references them, which is why this reads the relation rather than a flag.
        $hasStore = $user !== null && $user->store()->exists();

        if (! $hasStore) {
            throw ApiException::storeRequired();
        }

        return $next($request);
    }
}
