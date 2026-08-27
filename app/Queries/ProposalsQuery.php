<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Proposal;
use App\Models\Store;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reading proposals from the two sides a seller can be on (EP-27, EP-28, EP-29).
 *
 * A seller meets a proposal in one of two roles and they are not symmetrical. As the
 * **proposer** they are waiting on an answer and blocked from selling until they get
 * one. As a **reviewer** they are being asked for that answer about a product they
 * already carry. The same row, read for two different reasons, which is why these are
 * two queries rather than one with a flag.
 *
 * Eligibility is always read from the frozen `proposal_reviewers` set, never from
 * current attachments. A store that attached during the window is not in it, and a
 * store that detached is still in it. Querying attachments instead would silently
 * change who could vote as the market moved underneath the proposal.
 */
final class ProposalsQuery
{
    /**
     * Everything this store has proposed, whatever became of it.
     *
     * @return LengthAwarePaginator<int, Proposal>
     */
    public function mine(Store $store, int $perPage): LengthAwarePaginator
    {
        return Proposal::query()
            ->where('store_id', $store->id)
            ->with(['product'])
            ->withCount(['votes', 'reviewers'])
            /*
             * Newest first. A seller opening this screen is nearly always asking about
             * the thing they just submitted, not about something resolved last month.
             */
            ->orderByDesc('review_opens_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Proposals this store was asked to review.
     *
     * Only ones still open. A closed window cannot take another vote, so listing
     * resolved proposals here would be offering work that cannot be done.
     *
     * Already voted ones are kept rather than filtered out, and the resource carries
     * `has_voted` so the screen can separate them. A reviewer who voted yesterday and
     * comes back to check should find the proposal where they left it rather than
     * discover it has vanished.
     *
     * @return LengthAwarePaginator<int, Proposal>
     */
    public function toReview(Store $store, int $perPage): LengthAwarePaginator
    {
        return Proposal::query()
            ->where('status', Proposal::STATUS_PENDING)
            ->where('review_closes_at', '>', now())
            ->whereHas('reviewers', static fn ($query) => $query->where('store_id', $store->id))
            ->with(['product'])
            ->withCount(['votes', 'reviewers'])
            /*
             * Soonest to close first, which is the opposite of `mine`. This is a queue
             * of work with deadlines, and the one about to expire is the one where a
             * vote still changes the outcome.
             */
            ->orderBy('review_closes_at')
            ->paginate($perPage);
    }

    /**
     * One proposal, for a store entitled to see it.
     *
     * Returns null when the caller is neither the proposer nor a frozen reviewer. The
     * controller turns that into a 404 rather than a 403, because which products a
     * competitor is arguing about is not something to confirm by the choice of status
     * code.
     */
    public function visibleTo(int $proposalId, Store $store): ?Proposal
    {
        $proposal = Proposal::query()
            ->whereKey($proposalId)
            ->with(['product'])
            ->withCount(['votes', 'reviewers'])
            ->first();

        if ($proposal === null) {
            return null;
        }

        $isProposer = $proposal->store_id === $store->id;

        return $isProposer || $proposal->hasReviewer($store->id) ? $proposal : null;
    }
}
