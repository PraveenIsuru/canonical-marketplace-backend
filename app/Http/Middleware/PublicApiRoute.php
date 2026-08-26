<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Public access level.
 *
 * Marks a route as anonymous readable. It resolves no session and must behave
 * identically whether or not a token happens to be present, because most catalogue
 * traffic is anonymous and resolving a session on the highest traffic paths costs
 * latency for no benefit.
 *
 * This middleware deliberately does almost nothing. Its value is that the absence of
 * session resolution on these routes is declared rather than accidental, and the
 * header it sets lets a test assert it.
 */
final class PublicApiRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Access-Level', 'public');

        return $response;
    }
}
