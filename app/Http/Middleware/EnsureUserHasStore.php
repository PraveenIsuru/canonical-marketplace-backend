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

        // The store relation does not exist until M4. Until then this refuses
        // everything, which is correct: nobody has a store yet.
        $hasStore = $user !== null
            && method_exists($user, 'store')
            && $user->store()->exists();

        if (! $hasStore) {
            throw ApiException::storeRequired();
        }

        return $next($request);
    }
}
