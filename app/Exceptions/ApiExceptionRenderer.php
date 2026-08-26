<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Turns every exception into the three field error envelope.
 *
 * Clients branch on `code`, never on `message`, so codes are fixed strings and must
 * not be reworded. Messages may change freely.
 *
 * This exists from day one, before any feature endpoint, because retrofitting a
 * consistent error shape later means touching every controller that was written
 * without it.
 *
 * See development-docs/shared/api-contract.md, sections 1 and 7.
 */
final class ApiExceptionRenderer
{
    /** Only API routes get this treatment. The Inertia side is left alone. */
    public static function shouldHandle(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldHandle($request)) {
            return null;
        }

        [$status, $code, $message, $errors] = self::describe($e);

        $body = ['code' => $code, 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        // The queued job id sits at the top level, not inside data, so the client can
        // poll it and resume a flow that provider unavailability blocked.
        if ($e instanceof AiUnavailableException) {
            $body['queued_job_id'] = $e->queuedJobId();
        }

        // Surface the real message in local development, where a generic 500 hides
        // the cause and slows everything down.
        if ($status === 500 && config('app.debug')) {
            $body['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return response()->json($body, $status);
    }

    /**
     * Maps an exception to its status, code, message, and optional field errors.
     *
     * @return array{0: int, 1: string, 2: string, 3: array<string, array<int, string>>|null}
     */
    private static function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [
                $e->status(),
                $e->errorCode(),
                $e->getMessage(),
                $e->errors(),
            ],

            $e instanceof ValidationException => [
                422,
                'validation_failed',
                'The given data was invalid.',
                $e->errors(),
            ],

            $e instanceof AuthenticationException => [
                401,
                'unauthenticated',
                'Authentication is required.',
                null,
            ],

            $e instanceof AuthorizationException => [
                403,
                'forbidden',
                'You are not permitted to do that.',
                null,
            ],

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => [
                404,
                'not_found',
                'The requested resource does not exist.',
                null,
            ],

            $e instanceof TooManyRequestsHttpException => [
                429,
                'rate_limited',
                'Too many requests. Try again shortly.',
                null,
            ],

            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                self::codeForStatus($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.',
                null,
            ],

            default => [
                500,
                'server_error',
                'Something went wrong on our side.',
                null,
            ],
        };
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            429 => 'rate_limited',
            default => $status >= 500 ? 'server_error' : 'request_failed',
        };
    }
}
