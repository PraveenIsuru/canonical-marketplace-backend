<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminDecisionRequest;
use App\Http\Resources\AdminProposalDetailResource;
use App\Http\Resources\AdminProposalSummaryResource;
use App\Models\Proposal;
use App\Queries\AdminProposalsQuery;
use App\Services\Admin\AdminDecision;
use App\Services\Admin\AdminProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Administrator proposal work (EP-40, EP-41, EP-42, EP-58, EP-59).
 *
 * **The escalation queue is the reason this controller exists.** The resolution matrix
 * escalates on a tie, on nobody voting, and on a well evidenced submission the
 * incumbents disagree with, and in each of those cases the proposing seller stays
 * unable to list the product until somebody here answers. That gap has been open since
 * M7 and these two writes close it.
 *
 * Thin. Every decision lives in AdminProposalService, and the reads live in
 * AdminProposalsQuery.
 */
final class AdminProposalController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AdminProposalsQuery $proposals,
        private readonly AdminProposalService $decisions,
    ) {}

    /**
     * EP-40 The escalation queue, oldest first.
     *
     * Ordered by when the seller was blocked rather than by when the proposal
     * escalated, so the row at the top is whoever has been waiting longest.
     */
    public function escalations(Request $request): AnonymousResourceCollection
    {
        return AdminProposalSummaryResource::collection(
            $this->proposals->escalations($this->perPage($request)),
        );
    }

    /** EP-58 Every proposal, newest first, optionally filtered by status. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        $allowed = [
            Proposal::STATUS_PENDING,
            Proposal::STATUS_APPROVED,
            Proposal::STATUS_REJECTED,
            Proposal::STATUS_ESCALATED,
        ];

        return AdminProposalSummaryResource::collection(
            $this->proposals->all(
                $this->perPage($request),
                // An unrecognised status is ignored rather than refused. It can only
                // come from a hand written URL, and answering the unfiltered list is a
                // more useful response than a validation error.
                in_array($status, $allowed, true) ? $status : null,
            ),
        );
    }

    /** EP-59 One proposal, with the change comparison, the votes, and their comments. */
    public function show(int $proposal): AdminProposalDetailResource
    {
        $found = $this->proposals->find($proposal)
            ?? throw ApiException::notFound('That proposal does not exist.');

        return new AdminProposalDetailResource($found);
    }

    /**
     * EP-41 Settles an escalated proposal.
     *
     * **Both outcomes unblock the seller.** What blocked them was an unresolved
     * proposal, not an unfavourable one, so approval releases the listing they were
     * waiting on and rejection releases them to try again. The response says so
     * explicitly, because interface copy that describes rejection as leaving them
     * blocked would be wrong.
     */
    public function resolve(AdminDecisionRequest $request, int $proposal): JsonResponse
    {
        $decision = $this->decisions->resolveEscalation(
            $this->find($proposal),
            $request->user(),
            $request->isApproval(),
        );

        return $this->decisionResponse($decision);
    }

    /**
     * EP-42 Reverses a decision that has already been made.
     *
     * Reversing an approval writes a **further version** and deletes nothing. The record
     * moves forward to a state resembling the one before, and the chain keeps every
     * version it had, including the one being reversed.
     */
    public function override(AdminDecisionRequest $request, int $proposal): JsonResponse
    {
        $decision = $this->decisions->override(
            $this->find($proposal),
            $request->user(),
            $request->isApproval(),
        );

        return $this->decisionResponse($decision);
    }

    private function decisionResponse(AdminDecision $decision): JsonResponse
    {
        return response()->json([
            'data' => [
                'proposal_id' => $decision->proposalId,
                'status' => $decision->status,
                'resolved_at' => $decision->resolvedAt,
                'version_number' => $decision->versionNumber,
                'attachments_created' => $decision->attachmentsCreated,
                'seller_unblocked' => $decision->sellerUnblocked,
            ],
        ]);
    }

    private function find(int $id): Proposal
    {
        return Proposal::find($id)
            ?? throw ApiException::notFound('That proposal does not exist.');
    }

    /** Capped at 100 by the contract, section 2. */
    private function perPage(Request $request): int
    {
        return min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', 20)));
    }
}
