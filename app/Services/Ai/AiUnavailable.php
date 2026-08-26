<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI provider could not answer.
 *
 * Deliberately not an ApiException. This is a domain event that callers decide how to
 * present: buyer search degrades to keyword results and returns 200, while every other
 * path queues the work and returns 503. Making it an HTTP exception would force one of
 * those behaviours on both.
 */
final class AiUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("The AI provider could not answer: {$reason}");
    }
}
