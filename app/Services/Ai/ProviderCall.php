<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * The failures every provider shares, handled once.
 *
 * A request can time out, the host can be unreachable, and the vendor can refuse. Those
 * three go the same way whoever is answering, and handling them per transport would mean
 * a newly added provider quietly omitting one.
 *
 * A static class rather than a base class for transports to extend: nothing in
 * app/Services uses inheritance, and a transport that composed this reads the same as
 * every other class here.
 */
final class ProviderCall
{
    /**
     * Make the call, or fail in the platform's vocabulary rather than the client's.
     *
     * @param  callable(): Response  $send
     *
     * @throws AiUnavailable
     */
    public static function orFail(callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException) {
            throw AiUnavailable::because('the request timed out or the host was unreachable');
        } catch (Throwable $e) {
            throw AiUnavailable::because($e->getMessage());
        }

        if ($response->failed()) {
            throw AiUnavailable::because(
                "the provider returned HTTP {$response->status()}: ".self::reasonFrom($response),
            );
        }

        return $response;
    }

    /**
     * Whatever the provider said about a request it refused.
     *
     * The status code alone is not enough to act on. A wrong model name, a key that has
     * been revoked and an account with no credit left all come back as HTTP 400, so a
     * log line carrying only the number cannot tell them apart and every one of them
     * looks like a bug in the adapter. The body says which it was, so it belongs in the
     * message. Both vendors put it in the same place.
     *
     * Only ever read from the response, so there is no risk of the key being echoed
     * into a log by accident.
     */
    private static function reasonFrom(Response $response): string
    {
        $reason = $response->json('error.message');

        if (is_string($reason) && trim($reason) !== '') {
            return trim($reason);
        }

        // Not the documented error shape, which usually means the reply came from
        // something in front of the API rather than the API itself. The raw body is
        // still the most useful thing available, capped because a proxy error page can
        // be an entire HTML document and the log only needs enough to recognise it.
        $body = trim($response->body());

        return $body === '' ? 'no reason was given' : Str::limit($body, 300);
    }
}
