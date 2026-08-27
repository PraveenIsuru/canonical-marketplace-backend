<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One proposal in a list (EP-27, EP-28), per section 11.8 of the contract.
 *
 * Serves both sides of the review. The proposing seller reads it to find out what
 * became of their submission; a reviewer reads it as a queue of work with deadlines.
 * What differs between the two is `has_voted`, which describes the calling store.
 *
 * **No confidence score and no confidence band.** They decide the outcome server side
 * and are returned to nobody, the proposing seller included. A reviewer who could see
 * the AI's assessment would be voting on the assessment rather than on the product
 * they actually stock, which is the one thing peer review exists to avoid.
 *
 * @property Proposal $resource
 */
final class ProposalSummaryResource extends JsonResource
{
    /**
     * @param  int|null  $callerStoreId  The store reading this, for `has_voted`.
     */
    public function __construct(Proposal $resource, private readonly ?int $callerStoreId = null)
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
            'review_opens_at' => $proposal->review_opens_at->toIso8601String(),
            'review_closes_at' => $proposal->review_closes_at->toIso8601String(),
            'resolved_at' => $proposal->resolved_at?->toIso8601String(),

            /*
             * The field names only, not the values. A list is for recognising which
             * submission this is, and the full comparison is what EP-29 is for.
             */
            'changed_fields' => array_keys($proposal->changes),

            'product' => [
                'id' => $proposal->product->id,
                'slug' => $proposal->product->slug,
                'name' => $proposal->product->name,
            ],

            /*
             * Votes cast against the frozen reviewer set, not against the stores
             * carrying the product today. The two drift apart during the window, and
             * the frozen number is the one the outcome was decided on.
             *
             * `votes_cast` is also the matrix's denominator: a reviewer who does not
             * vote is excluded rather than counted as opposed, so two in favour out of
             * five eligible is a majority in favour.
             */
            'votes_cast' => (int) ($proposal->votes_count ?? $proposal->votes()->count()),
            'reviewer_count' => (int) ($proposal->reviewers_count ?? $proposal->reviewers()->count()),

            'has_voted' => $this->callerStoreId !== null
                && $proposal->votes()->where('store_id', $this->callerStoreId)->exists(),
        ];
    }
}
