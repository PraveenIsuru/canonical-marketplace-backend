<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * How well the AI thinks a seller's confirmation answers hold together.
 *
 * This value never leaves the server. It is written to the proposal, it decides the
 * resolution matrix at M7, and it appears in no response body on any endpoint at any
 * access level. Showing it to a reviewer would anchor their vote on the AI's opinion
 * instead of on what they know about the product, which is the one thing peer review
 * is there to contribute.
 *
 * The reason is kept alongside the score for the same purpose the raw score is kept:
 * so a later change to the band threshold can be judged against evidence rather than
 * guessed at. It is internal, and it is not a message for anyone outside the platform.
 */
final readonly class ConfidenceAssessment
{
    public function __construct(
        /** Between 0 and 1, to three decimal places once stored. */
        public float $score,
        /** Internal only. Never rendered, never returned. */
        public string $reason = '',
    ) {}
}
