<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * One product the AI considers a plausible match, and how sure it is.
 *
 * Deliberately only an id and a score. The provider is told what the candidates are
 * and asked to judge them; it never decides what the client is shown. The application
 * loads the product and builds the response, so a change to the candidate shape does
 * not reach into an adapter.
 */
final readonly class ProductMatchCandidate
{
    public function __construct(
        public int $productId,
        /** Between 0 and 1. Higher is a closer match. */
        public float $score,
    ) {}
}
