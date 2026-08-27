<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Whether a photograph shows the product beside the code the platform issued.
 *
 * Unlike ConfidenceAssessment, the reason here **is** meant for the person concerned.
 * A buyer who failed verification is deciding whether to spend one of five attempts on
 * another try, and "the code was not legible" and "this is a different product" call
 * for completely different next moves.
 *
 * What is never returned, on any path, is the photograph itself or where it lived. It
 * is deleted the moment verification concludes, on a pass and on a failure alike, and
 * this object is deliberately incapable of carrying a path.
 */
final readonly class OwnershipAssessment
{
    public function __construct(
        public bool $passed,
        /** Shown to the buyer. Say what was wrong, not how the model reasoned. */
        public string $reason,
    ) {}

    public static function passed(string $reason = 'The code and the product are both visible.'): self
    {
        return new self(true, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}
