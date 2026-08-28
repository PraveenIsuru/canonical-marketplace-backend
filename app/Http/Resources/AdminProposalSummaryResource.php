<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One proposal as an administrator sees it (EP-40, EP-58), per section 11.12.
 *
 * **Names the proposing store, where the reviewer's shape does not.** The asymmetry is
 * deliberate. A reviewer is asked whether a claim about a product is right, and telling
 * them which competitor made it invites voting on the seller instead. An administrator
 * settling an escalation is doing the opposite job and cannot decide fairly without
 * knowing who is blocked and for how long.
 *
 * **Still no confidence score and no confidence band.** Section 6 has no exceptions and
 * this is not one. An administrator deciding a disagreement between a seller and the
 * incumbents should decide on the evidence, and the AI's number would anchor that
 * exactly as it would anchor a reviewer.
 *
 * @property Proposal $resource
 */
class AdminProposalSummaryResource extends JsonResource
{
    public function __construct(Proposal $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $proposal = $this->resource;

        return [
            'id' => $proposal->id,
            'status' => $proposal->status,

            /*
             * The coded reason the matrix recorded, and null while a proposal is still
             * pending. Left exactly as the matrix wrote it even after an administrator
             * settles the proposal: it records *why this escalated*, which is a
             * different fact from *who settled it*, and the second is `resolved_by`.
             */
            'resolution_reason' => $proposal->resolution_reason,

            'review_opens_at' => $proposal->review_opens_at->toIso8601String(),
            'review_closes_at' => $proposal->review_closes_at->toIso8601String(),
            'resolved_at' => $proposal->resolved_at?->toIso8601String(),

            'changed_fields' => array_keys($proposal->changes),

            'product' => [
                'id' => $proposal->product->id,
                'slug' => $proposal->product->slug,
                'name' => $proposal->product->name,
            ],

            'store' => [
                'id' => $proposal->store->id,
                'name' => $proposal->store->name,
            ],

            /*
             * The split, which reviewers never see and an administrator always needs.
             * The two sum to `votes_cast`, and `reviewer_count` is the frozen set, so
             * the gap between them is the reviewers who said nothing. Non voters are
             * excluded rather than counted as opposed, which is why one vote out of
             * five can carry a proposal.
             */
            'votes_cast' => (int) ($proposal->votes_count ?? 0),
            'votes_in_favour' => (int) ($proposal->votes_in_favour_count ?? 0),
            'votes_against' => (int) ($proposal->votes_against_count ?? 0),
            'reviewer_count' => (int) ($proposal->reviewers_count ?? 0),
        ];
    }
}
