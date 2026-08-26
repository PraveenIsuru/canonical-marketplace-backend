<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Admin access level.
 *
 * Administrators are a flag on the user, not a separate identity, because one account
 * may hold buyer and seller roles at the same time.
 */
final class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin !== true) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
