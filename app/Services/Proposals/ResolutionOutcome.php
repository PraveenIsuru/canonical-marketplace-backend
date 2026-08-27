<?php

declare(strict_types=1);

namespace App\Services\Proposals;

use App\Models\Proposal;

/**
 * What the resolution matrix decided, and why.
 *
 * The reason is stored alongside the status because "rejected" on its own is not an
 * audit record. Six months later the question is not what happened but why, and
 * `low_confidence_peers_against` answers it where `rejected` does not.
 */
final readonly class ResolutionOutcome
{
    public const REASON_HIGH_FAVOUR = 'high_confidence_peers_favour';

    public const REASON_HIGH_AGAINST = 'high_confidence_peers_against';

    public const REASON_LOW_FAVOUR = 'low_confidence_peers_favour';

    public const REASON_LOW_AGAINST = 'low_confidence_peers_against';

    public const REASON_NO_VOTES = 'no_votes_cast';

    /**
     * Not in the schema document's list of reasons, and needed anyway.
     *
     * The build plan requires a tie to escalate, but a tie is neither peers in favour
     * nor peers against, so none of the four matrix reasons describes it. Recording it
     * as one of them would misreport what the reviewers actually did.
     */
    public const REASON_TIE = 'tie_no_majority';

    private function __construct(
        public string $status,
        public string $reason,
    ) {}

    public static function approved(string $reason): self
    {
        return new self(Proposal::STATUS_APPROVED, $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(Proposal::STATUS_REJECTED, $reason);
    }

    public static function escalated(string $reason): self
    {
        return new self(Proposal::STATUS_ESCALATED, $reason);
    }

    public function isApproval(): bool
    {
        return $this->status === Proposal::STATUS_APPROVED;
    }
}
