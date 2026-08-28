<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Jobs\IndexProduct;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Catalogue\ProductVersionService;
use App\Services\Proposals\ProposalResolutionService;
use Illuminate\Support\Facades\DB;

/**
 * Administrator decisions on proposals (EP-41, EP-42).
 *
 * **This is the only thing in the platform that unblocks a seller whose proposal
 * escalated.** The matrix escalates on a tie, on nobody voting, and on a well evidenced
 * submission the incumbents disagree with, and in every one of those cases the seller
 * stays unable to list the product until an administrator answers. That gap has been
 * open since M7 and this closes it.
 *
 * What approval means lives in ProposalResolutionService and is called from here rather
 * than reimplemented, so a proposal accepted by peers and one accepted by an
 * administrator cause exactly the same things.
 */
final class AdminProposalService
{
    public function __construct(
        private readonly ProposalResolutionService $resolution,
        private readonly ProductVersionService $versions,
    ) {}

    /**
     * EP-41 Settles an escalated proposal.
     *
     * **Both outcomes unblock the seller**, and that is the substance of this endpoint
     * rather than a detail of it. Approval releases the listing they were waiting on;
     * rejection releases them to try again. What was blocking them was the absence of an
     * answer, so any answer ends it.
     *
     * `resolution_reason` is left exactly as the matrix wrote it. It records *why this
     * escalated*, which is a different fact from *who settled it*, and overwriting one
     * with the other would trade a fact for a fact rather than adding one. The
     * administrator is recorded in `resolved_by_user_id`.
     */
    public function resolveEscalation(Proposal $proposal, User $administrator, bool $approve): AdminDecision
    {
        return DB::transaction(function () use ($proposal, $administrator, $approve): AdminDecision {
            $locked = $this->lock($proposal);

            /*
             * Two administrators working the same queue is an ordinary race rather than
             * a fault, and the refusal says which state was required so the screen can
             * tell them to refresh rather than showing a generic failure.
             */
            if ($locked->status !== Proposal::STATUS_ESCALATED) {
                throw ApiException::proposalNotEscalated();
            }

            return $this->settle($locked, $administrator, $approve);
        });
    }

    /**
     * EP-42 Reverses a decision that has already been made.
     *
     * Only on a proposal that resolved. An escalated proposal has been decided by
     * nobody, so there is nothing to override and EP-41 is the endpoint for it.
     *
     * Overriding to the status a proposal already holds is allowed and is a no op in
     * everything but the audit trail: it records that an administrator looked and let it
     * stand, which is worth more than a refusal that leaves no trace of the review.
     */
    public function override(Proposal $proposal, User $administrator, bool $approve): AdminDecision
    {
        return DB::transaction(function () use ($proposal, $administrator, $approve): AdminDecision {
            $locked = $this->lock($proposal);

            $isResolved = in_array(
                $locked->status,
                [Proposal::STATUS_APPROVED, Proposal::STATUS_REJECTED],
                true,
            );

            if (! $isResolved) {
                throw ApiException::proposalNotResolved();
            }

            $wasApproved = $locked->status === Proposal::STATUS_APPROVED;

            // Turning an approval back into a rejection is the only path that has to
            // undo something already written to the record.
            if ($wasApproved && ! $approve) {
                return $this->reverseApproval($locked, $administrator);
            }

            // Rejected into approved behaves exactly like any other approval, and an
            // override that changes nothing still records who reviewed it.
            if (! $wasApproved && $approve) {
                return $this->settle($locked, $administrator, true);
            }

            return $this->settle($locked, $administrator, $approve, applyEffects: false);
        });
    }

    /**
     * Writes the decision and, on an approval, everything it causes.
     *
     * @param  bool  $applyEffects  false where the proposal already holds this outcome,
     *                              so an override that changes nothing does not write a
     *                              second version or a duplicate attachment
     */
    private function settle(
        Proposal $proposal,
        User $administrator,
        bool $approve,
        bool $applyEffects = true,
    ): AdminDecision {
        $proposal->forceFill([
            'status' => $approve ? Proposal::STATUS_APPROVED : Proposal::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $administrator->id,
        ])->save();

        $effects = $approve && $applyEffects
            ? $this->resolution->applyApproval($proposal)
            : null;

        return new AdminDecision(
            proposalId: $proposal->id,
            status: $proposal->status,
            resolvedAt: $proposal->resolved_at->toIso8601String(),
            versionNumber: $effects?->versionNumber,
            attachmentsCreated: $effects->attachmentsCreated ?? 0,
            // True either way. The block was the open question, not the answer.
            sellerUnblocked: true,
        );
    }

    /**
     * Turns an approval back into a rejection.
     *
     * **Nothing is deleted and nothing is rolled back.** The record moves *forward* to a
     * state resembling the one before the approval, by writing a further version. The
     * chain keeps every version it had, including the one being reversed, because the
     * history of what the catalogue claimed is the thing a version chain is for.
     *
     * Two things deliberately survive:
     *
     *  - **Attribute options the approval added, and every combination generated from
     *    them.** Invariant 2 forbids removing a combination, by anyone, an administrator
     *    included. A reversal that stranded generated combinations could never be
     *    cleaned up afterwards, so widening is treated as permanent even when the claim
     *    that caused it is withdrawn.
     *  - **The proposing seller's attachment.** Reversing a claim about what a product
     *    *is* says nothing about whether that shop stocks it.
     */
    private function reverseApproval(Proposal $proposal, User $administrator): AdminDecision
    {
        $product = $proposal->product;

        $this->restoreReversibleFields($product, $proposal->changes);

        $version = $this->versions->record(
            $product->refresh(),
            causedByUser: $administrator,
            isAdminOriginated: true,
        );

        $proposal->forceFill([
            'status' => Proposal::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $administrator->id,
        ])->save();

        DB::afterCommit(fn () => IndexProduct::dispatch($product->id));

        return new AdminDecision(
            proposalId: $proposal->id,
            status: $proposal->status,
            resolvedAt: $proposal->resolved_at->toIso8601String(),
            versionNumber: $version->version_number,
            attachmentsCreated: 0,
            sellerUnblocked: true,
        );
    }

    /**
     * Puts back the values an approval overwrote, where putting them back is possible.
     *
     * Scalar fields and specifications restore to what the proposal recorded as `from`.
     * A specification whose `from` was null is removed again, because the record did not
     * hold that key before the approval invented it.
     *
     * **An attribute is skipped entirely.** Narrowing its options would strand the
     * combinations generated from them, which is the one thing no code in this platform
     * may do.
     *
     * @param  array<string, array{from: string|null, to: string}>  $changes
     */
    private function restoreReversibleFields(Product $product, array $changes): void
    {
        $specifications = $product->specifications ?? [];

        foreach ($changes as $attribute => $change) {
            $previous = $change['from'] ?? null;

            if (in_array($attribute, ['name', 'description', 'category'], true)) {
                // A null `from` on a name or a category cannot be restored, because the
                // column is not nullable and the record has to say something.
                if ($previous !== null) {
                    $product->{$attribute} = $previous;
                }

                continue;
            }

            if ($product->productAttributes()->where('name', $attribute)->exists()) {
                continue;
            }

            if ($previous === null) {
                unset($specifications[$attribute]);

                continue;
            }

            $specifications[$attribute] = $previous;
        }

        $product->specifications = $specifications;
        $product->save();
    }

    /**
     * Reads the row under a lock.
     *
     * Two administrators resolving the same escalation at the same instant would
     * otherwise both see it escalated, both pass the state check, and both apply an
     * outcome, writing two versions of one decision.
     */
    private function lock(Proposal $proposal): Proposal
    {
        /** @var Proposal $locked */
        $locked = Proposal::whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
