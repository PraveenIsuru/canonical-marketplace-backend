<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VoteOnProposalRequest;
use App\Http\Resources\ProposalDetailResource;
use App\Http\Resources\ProposalSummaryResource;
use App\Models\Proposal;
use App\Models\Store;
use App\Queries\ProposalsQuery;
use App\Services\Proposals\ProposalResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Peer review (EP-27 to EP-30).
 *
 * The half of the platform that decides what a canonical record says. No seller edits
 * a product, so every change arrives as a proposal and is settled either by the
 * sellers who already carry the product or, failing that, by an administrator.
 *
 * Thin by design. Eligibility, the closed window, double voting, and the resolution
 * matrix all live in ProposalResolutionService, and the reads live in ProposalsQuery.
 * These methods translate between HTTP and those, and decide only how to fail.
 */
final class ProposalController extends Controller
{
    public function __construct(
        private readonly ProposalsQuery $proposals,
        private readonly ProposalResolutionService $resolution,
    ) {}

    /**
     * EP-27 The caller's own proposals.
     *
     * Every status, not only the blocking ones. A seller wants to know that the thing
     * they submitted last week was approved as much as they want to know what is still
     * outstanding, and a list that quietly dropped resolved proposals would look like
     * the submission had been lost.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $store = $this->callerStore($request);

        $proposals = $this->proposals->mine($store, $this->perPage($request));

        return ProposalSummaryResource::collection(
            $proposals->through(static fn (Proposal $proposal): ProposalSummaryResource => new ProposalSummaryResource($proposal, $store->id)),
        );
    }

    /**
     * EP-28 The reviews this store has been asked for.
     *
     * Read from the frozen reviewer set recorded when each proposal opened, never from
     * what the store carries today. A store that attached to the product mid window is
     * not in the set and sees nothing; a store that detached is still in it and is
     * still asked, because its vote was already owed.
     */
    public function toReview(Request $request): AnonymousResourceCollection
    {
        $store = $this->callerStore($request);

        $proposals = $this->proposals->toReview($store, $this->perPage($request));

        return ProposalSummaryResource::collection(
            $proposals->through(static fn (Proposal $proposal): ProposalSummaryResource => new ProposalSummaryResource($proposal, $store->id)),
        );
    }

    /**
     * EP-29 One proposal, with the change comparison.
     *
     * Visible to the proposing store and to the frozen reviewers, and to nobody else.
     * A store that is neither gets **404 rather than 403**: which products a competitor
     * is arguing about is not something to confirm by the choice of status code.
     */
    public function show(Request $request, int $proposal): ProposalDetailResource
    {
        $store = $this->callerStore($request);

        $found = $this->proposals->visibleTo($proposal, $store)
            ?? throw ApiException::notFound('That proposal does not exist.');

        return new ProposalDetailResource($found, $store);
    }

    /**
     * EP-30 Vote on a proposal.
     *
     * The vote may resolve the proposal immediately, when it was the last one
     * outstanding, so the response carries the status afterwards rather than the status
     * before. Section 11.6: the screen shows the outcome directly instead of polling
     * for it.
     *
     * A resolution here runs the same matrix the scheduled sweep runs. There is one
     * implementation, so a proposal completed by voting and one that expired cannot be
     * decided differently.
     */
    public function vote(VoteOnProposalRequest $request, int $proposal): JsonResponse
    {
        $store = $this->callerStore($request);

        /*
         * Loaded without the visibility filter EP-29 applies, and the difference is
         * deliberate. The contract registers `not_eligible_to_vote` as a 403 for a
         * store that was not attached when the proposal opened, so answering 404 here
         * would make that code unreachable and tell the caller nothing about why they
         * were refused.
         *
         * The asymmetry is safe: a 403 reveals only that some proposal holds this id,
         * where EP-29 would have handed over the product and the full comparison.
         */
        $found = Proposal::find($proposal)
            ?? throw ApiException::notFound('That proposal does not exist.');

        $resolved = $this->resolution->castVote(
            $found,
            $store,
            $request->isInFavour(),
            $request->comment(),
        );

        return response()->json([
            'data' => [
                'vote_recorded' => true,
                'proposal_status' => $resolved->status,
                'resolved_at' => $resolved->resolved_at?->toIso8601String(),
            ],
        ]);
    }

    /** Capped at 100 by the contract, section 2. */
    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('per_page', 20)));
    }

    private function callerStore(Request $request): Store
    {
        return $request->user()->store ?? throw ApiException::storeRequired();
    }
}
