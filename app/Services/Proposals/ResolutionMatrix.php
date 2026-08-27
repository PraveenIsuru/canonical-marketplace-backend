<?php

declare(strict_types=1);

namespace App\Services\Proposals;

use App\Models\Proposal;

/**
 * The resolution matrix, in one place.
 *
 * | AI confidence | Peer vote  | Outcome    |
 * |---------------|------------|------------|
 * | High          | In favour  | Approved   |
 * | High          | Against    | Escalated  |
 * | Low           | In favour  | Approved   |
 * | Low           | Against    | Rejected   |
 *
 * Both the vote endpoint and the scheduled sweep call this, and **only** this. Two
 * implementations of the same matrix would drift, and the drift would be invisible:
 * the two paths resolve different proposals, so nobody would ever see them disagree
 * about the same one.
 *
 * The one row worth explaining is high confidence with peers against. It escalates
 * rather than rejecting, because it is genuine disagreement between a well evidenced
 * submission and the incumbent sellers. Rejecting automatically would discard a
 * correct amendment from a seller who simply knows the product better than the people
 * already listing it, which is exactly the case the platform most needs to get right.
 *
 * Nothing here reads the database or writes anything. It is a pure decision, which is
 * what makes every row of it cheap to test.
 */
final class ResolutionMatrix
{
    /**
     * Decides the outcome from the confidence band and the votes actually cast.
     *
     * **Non voters are excluded from the denominator.** A proposal with five eligible
     * reviewers where two vote in favour and one against is a majority in favour, not
     * two out of five. Reviewers who say nothing have expressed no view, and counting
     * silence as opposition would let a proposal fail because people were busy.
     */
    public function decide(string $confidenceBand, int $inFavour, int $against): ResolutionOutcome
    {
        /*
         * Nobody voted at all. Escalated regardless of confidence, because an
         * unreviewed proposal has not been rejected by anyone: it has simply not been
         * looked at, and an administrator is who looks at it then.
         */
        if ($inFavour === 0 && $against === 0) {
            return ResolutionOutcome::escalated(ResolutionOutcome::REASON_NO_VOTES);
        }

        /*
         * A tie is not a majority either way, so neither matrix row applies. It goes to
         * an administrator rather than defaulting, because defaulting would mean
         * picking a side the reviewers deliberately did not pick.
         */
        if ($inFavour === $against) {
            return ResolutionOutcome::escalated(ResolutionOutcome::REASON_TIE);
        }

        $isHighConfidence = $confidenceBand === Proposal::BAND_HIGH;

        if ($inFavour > $against) {
            // Both confidence bands approve on a favourable majority. The band only
            // changes what happens when the peers disagree.
            return ResolutionOutcome::approved(
                $isHighConfidence
                    ? ResolutionOutcome::REASON_HIGH_FAVOUR
                    : ResolutionOutcome::REASON_LOW_FAVOUR,
            );
        }

        return $isHighConfidence
            ? ResolutionOutcome::escalated(ResolutionOutcome::REASON_HIGH_AGAINST)
            : ResolutionOutcome::rejected(ResolutionOutcome::REASON_LOW_AGAINST);
    }
}
