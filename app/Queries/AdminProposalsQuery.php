<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Proposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reading proposals as an administrator (EP-40, EP-58, EP-59).
 *
 * Separate from ProposalsQuery, which reads them as a seller, and the split is not
 * duplication. A seller sees the proposals they wrote or were asked to review; an
 * administrator sees all of them, with the proposing store named and the vote split
 * visible. Those are different questions and different visibility rules, so folding
 * them into one query would mean a flag deciding what a caller may see, which is the
 * shape mistakes hide in.
 *
 * Vote counts are aggregated in the database rather than by loading the votes, because
 * the escalation queue renders a tally per row and loading them would be a query per
 * proposal.
 */
final class AdminProposalsQuery
{
    /**
     * EP-40 The escalation queue, oldest first.
     *
     * **Ordered by when the seller was blocked, not by when the proposal escalated.**
     * The row at the top is the seller who has been waiting longest, which is the whole
     * purpose of this list: an escalated proposal is somebody unable to trade until an
     * administrator answers.
     *
     * @return LengthAwarePaginator<int, Proposal>
     */
    public function escalations(int $perPage): LengthAwarePaginator
    {
        return $this->base()
            ->where('status', Proposal::STATUS_ESCALATED)
            ->orderBy('review_opens_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * EP-58 Every proposal, newest first, optionally filtered by status.
     *
     * @return LengthAwarePaginator<int, Proposal>
     */
    public function all(int $perPage, ?string $status = null): LengthAwarePaginator
    {
        $query = $this->base();

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('review_opens_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * EP-59 One proposal, with everything a decision needs.
     *
     * The votes are loaded here rather than counted, because their comments are the
     * argument the administrator is being asked to settle and are the most useful thing
     * on the screen.
     */
    public function find(int $id): ?Proposal
    {
        return $this->base()
            ->with(['votes.store', 'resolvedBy'])
            ->whereKey($id)
            ->first();
    }

    /**
     * The shared select.
     *
     * `withCount` covers the tally without loading a single vote row, and the two
     * conditional counts are what give an administrator the split that reviewers never
     * see.
     *
     * @return Builder<Proposal>
     */
    private function base(): Builder
    {
        return Proposal::query()
            ->with(['product', 'store'])
            ->withCount([
                'votes',
                'reviewers',
                'votes as votes_in_favour_count' => fn ($query) => $query->where('vote', true),
                'votes as votes_against_count' => fn ($query) => $query->where('vote', false),
            ]);
    }
}
