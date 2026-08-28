<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProposalVote;
use Illuminate\Http\Request;

/**
 * One proposal in full, for the administrator deciding it (EP-59), per section 11.12.
 *
 * The list entry plus the three things a decision actually needs: what changed, what
 * the reviewers said about it, and what approving will release.
 *
 * Extends the summary rather than repeating it, so a field added there cannot be
 * forgotten here. That matters most for the field that is deliberately absent from
 * both: no confidence score reaches an administrator either.
 */
final class AdminProposalDetailResource extends AdminProposalSummaryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $proposal = $this->resource;

        return [
            ...parent::toArray($request),

            /*
             * An array rather than an object, so the order fields were reviewed in is
             * the order they are displayed in, exactly as section 11.8 has it. `from` is
             * what the record said before and is null where it held nothing.
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

            /*
             * The comments are the argument the administrator is being asked to settle,
             * and are the most useful thing on the screen. A reviewer who did not vote
             * is simply absent rather than present with a null vote: silence is not a
             * position, and the matrix does not treat it as one.
             */
            'votes' => $proposal->votes->map(static fn (ProposalVote $vote): array => [
                'store' => [
                    'id' => $vote->store->id,
                    'name' => $vote->store->name,
                ],
                'vote' => $vote->vote ? 'approve' : 'reject',
                'comment' => $vote->comment,
                'cast_at' => $vote->created_at->toIso8601String(),
            ])->all(),

            /*
             * What approval will create. No attachment row exists while a proposal
             * blocks a seller, so this is the listing being withheld, and an
             * administrator should see what they are about to release before releasing
             * it.
             */
            'intended_listing' => $proposal->intended_price_minor === null
                ? null
                : [
                    'variant_ids' => $proposal->intended_variant_ids ?? [],
                    // An integer in the smallest currency unit, like every price that
                    // crosses this boundary.
                    'price_minor' => $proposal->intended_price_minor,
                    'currency' => $proposal->intended_currency,
                ],

            /*
             * Named to other administrators only. No seller facing response carries it,
             * and section 11.11 states the matching rule from the other side: a version
             * never names the administrator who caused it.
             */
            'resolved_by' => $proposal->resolvedBy === null
                ? null
                : [
                    'id' => $proposal->resolvedBy->id,
                    'name' => $proposal->resolvedBy->name,
                ],
        ];
    }
}
