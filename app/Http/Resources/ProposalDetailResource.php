<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Proposal;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One proposal in full, with the change comparison (EP-29), per section 11.8.
 *
 * This is what a reviewer votes on, so it is the most consequential read in the
 * platform: what it shows decides what someone decides about a record every seller
 * shares.
 *
 * Three things it deliberately does not carry:
 *
 *  - **The confidence score or band.** Anchoring a reviewer on the AI's assessment
 *    defeats the point of asking a person who stocks the product.
 *  - **Per field accept or reject state.** A proposal is accepted or rejected as a
 *    whole, and offering the shape for a partial decision would invite a control that
 *    invariant 4 forbids.
 *  - **The proposing store's identity.** The vote is about whether the record is
 *    right, not about who said so, and naming the seller invites voting on the
 *    competitor rather than on the claim.
 *
 * @property Proposal $resource
 */
final class ProposalDetailResource extends JsonResource
{
    public function __construct(Proposal $resource, private readonly Store $callerStore)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $proposal = $this->resource;

        $isMine = $proposal->store_id === $this->callerStore->id;
        $hasVoted = $proposal->votes()->where('store_id', $this->callerStore->id)->exists();

        return [
            'id' => $proposal->id,
            'status' => $proposal->status,
            'review_opens_at' => $proposal->review_opens_at->toIso8601String(),
            'review_closes_at' => $proposal->review_closes_at->toIso8601String(),
            'resolved_at' => $proposal->resolved_at?->toIso8601String(),

            'product' => [
                'id' => $proposal->product->id,
                'slug' => $proposal->product->slug,
                'name' => $proposal->product->name,
            ],

            /*
             * An array rather than an object, so the order fields are reviewed in is
             * the order they are displayed in. `from` is what the record says now and
             * is null where it held nothing, which is a real case: a seller can
             * describe a specification the record never had.
             */
            'changes' => array_map(
                static fn (string $attribute, array $change): array => [
                    'attribute' => $attribute,
                    'from' => $change['from'] ?? null,
                    'to' => $change['to'],
                ],
                array_keys($proposal->changes),
                $proposal->changes,
            ),

            'votes_cast' => (int) ($proposal->votes_count ?? $proposal->votes()->count()),
            'reviewer_count' => (int) ($proposal->reviewers_count ?? $proposal->reviewers()->count()),
            'has_voted' => $hasVoted,
            'is_mine' => $isMine,

            /*
             * A rendering hint and nothing more. EP-30 re-checks all three conditions
             * and refuses regardless of what the client believed, because the window
             * can close and the proposal can resolve between this read and that write.
             */
            'can_vote' => ! $isMine
                && ! $hasVoted
                && $proposal->status === Proposal::STATUS_PENDING
                && ! $proposal->reviewHasClosed()
                && $proposal->hasReviewer($this->callerStore->id),
        ];
    }
}
