<?php

declare(strict_types=1);

namespace App\Services\Proposals;

/**
 * What an approval actually did.
 *
 * Returned rather than discarded because M11 has to report it. An administrator who
 * resolves an escalation is told which version their decision wrote and whether the
 * seller's withheld listing was created, and neither is knowable from the proposal row
 * afterwards without going looking.
 */
final readonly class ApprovalEffects
{
    public function __construct(
        public int $versionNumber,
        public int $attachmentsCreated,
    ) {}
}
