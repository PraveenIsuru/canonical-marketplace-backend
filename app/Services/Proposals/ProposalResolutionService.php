<?php

declare(strict_types=1);

namespace App\Services\Proposals;

use App\Exceptions\ApiException;
use App\Jobs\IndexProduct;
use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Proposal;
use App\Models\ProposalVote;
use App\Models\Store;
use App\Models\Variant;
use App\Services\Catalogue\AttributeService;
use App\Services\Catalogue\ProductVersionService;
use App\Services\Catalogue\VariantGenerationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolving a proposal, and everything that follows from it.
 *
 * Called from two places and nowhere else: the vote endpoint, when the last eligible
 * reviewer has voted, and the scheduled sweep, when a review window runs out. Both go
 * through the same matrix, so the two can never disagree.
 *
 * What each outcome does:
 *
 *  - **Approved.** The changes are applied to the record, a version is written, and
 *    the attachment the proposal was withholding is finally created. The seller is
 *    listed and the store may go live.
 *  - **Rejected.** Neither a version nor an attachment. The record is untouched and
 *    the seller may start a fresh attempt.
 *  - **Escalated.** Nothing yet. The seller stays blocked, because they are still
 *    owed an answer and an administrator has not given one.
 */
final class ProposalResolutionService
{
    public function __construct(
        private readonly ResolutionMatrix $matrix,
        private readonly ProductVersionService $versions,
        private readonly VariantGenerationService $variants,
        private readonly AttributeService $attributes,
    ) {}

    /**
     * Resolves a proposal if it is ready, and returns it either way.
     *
     * Takes a row lock before reading the votes. Two reviewers voting at the same
     * instant would otherwise both see the same tally, both decide the proposal was
     * complete, and both apply the outcome, creating two versions of the same change.
     * The lock is what makes "resolving exactly once" true rather than usually true.
     */
    public function resolveIfReady(Proposal $proposal, bool $windowHasClosed = false): Proposal
    {
        return DB::transaction(function () use ($proposal, $windowHasClosed): Proposal {
            /** @var Proposal $locked */
            $locked = Proposal::whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();

            // Someone else resolved it while this request was waiting on the lock.
            if ($locked->status !== Proposal::STATUS_PENDING) {
                return $locked;
            }

            $eligible = $locked->reviewers()->count();
            $cast = $locked->votes()->count();

            /*
             * Resolve early only when every eligible reviewer has spoken. Waiting out
             * the full three days once the answer cannot change would block the
             * proposing seller for no reason.
             *
             * A proposal with no eligible reviewers at all never resolves early: there
             * is nobody who could complete it, so it waits for the sweep and escalates.
             */
            $everyoneVoted = $eligible > 0 && $cast >= $eligible;

            if (! $windowHasClosed && ! $everyoneVoted) {
                return $locked;
            }

            $inFavour = $locked->votes()->where('vote', true)->count();
            $against = $locked->votes()->where('vote', false)->count();

            $outcome = $this->matrix->decide($locked->confidence_band, $inFavour, $against);

            $locked->forceFill([
                'status' => $outcome->status,
                'resolution_reason' => $outcome->reason,
                'resolved_at' => now(),
            ])->save();

            if ($outcome->isApproval()) {
                $this->applyApproval($locked);
            }

            return $locked;
        });
    }

    /**
     * Everything an approved proposal causes.
     *
     * Order matters. The changes are applied first so the version snapshot describes
     * the record as it now is, then the attachment is released, then the store's
     * visibility is recomputed from its new attachment count.
     *
     * Public because M11 calls it too. An administrator resolving an escalation in the
     * seller's favour, and an administrator overriding a rejection into an approval,
     * must cause exactly what a peer approval causes. Two implementations of what
     * approval means would drift, and the drift would surface only as a seller who was
     * unblocked and never listed.
     */
    public function applyApproval(Proposal $proposal): ApprovalEffects
    {
        $product = $proposal->product;

        $this->applyChanges($product, $proposal->changes);

        /*
         * A version exists for an accepted proposal and an administrator edit, and for
         * nothing else. A rejected proposal never reaches here.
         *
         * Attributed to the **proposing store**, including when an administrator is the
         * one who accepted it at M11. The change is the seller's, and whoever decided it
         * is recorded on the proposal instead.
         */
        $version = $this->versions->record(
            $product->refresh(),
            causedByStore: $proposal->store,
            causedByUser: $proposal->store?->user,
            proposalId: $proposal->id,
        );

        $attachmentsCreated = $this->releaseAttachment($proposal);

        // Dispatched after the surrounding transaction commits, so the index never
        // advertises a record that then rolled back.
        DB::afterCommit(fn () => IndexProduct::dispatch($product->id));

        return new ApprovalEffects($version->version_number, $attachmentsCreated);
    }

    /**
     * Writes the proposed values onto the record.
     *
     * A proposal is accepted **as a whole**, so every field in it is applied. Applying
     * some and skipping others would quietly turn an all or nothing decision into a
     * partial one, which is the thing invariant 4 exists to prevent.
     *
     * @param  array<string, array{from: string|null, to: string}>  $changes
     */
    private function applyChanges(Product $product, array $changes): void
    {
        $specifications = $product->specifications ?? [];
        $touchedAttributes = false;

        foreach ($changes as $attribute => $change) {
            $value = $change['to'];

            if (in_array($attribute, ['name', 'description', 'category'], true)) {
                $product->{$attribute} = $value;

                continue;
            }

            $definition = $product->productAttributes()->where('name', $attribute)->first();

            if ($definition !== null) {
                $this->applyAttributeOptions($definition, $value);
                $touchedAttributes = true;

                continue;
            }

            // Anything else is a structured fact about the product, which lives in the
            // specifications document rather than in a column.
            $specifications[$attribute] = $value;
        }

        $product->specifications = $specifications;
        $product->save();

        if ($touchedAttributes) {
            $this->regenerateCombinations($product->refresh());
        }
    }

    /**
     * Widens an attribute's option list from what the seller typed.
     *
     * The merge itself lives in AttributeService, because an administrator widens the
     * same lists at M11 and two implementations of additive would eventually disagree
     * about case or whitespace. All this does is turn the proposal's comma separated
     * string into the list that service takes.
     */
    private function applyAttributeOptions(ProductAttribute $definition, string $proposed): void
    {
        $this->attributes->widen($definition, $this->attributes->parseOptionList($proposed));
    }

    /**
     * Generates any combinations the widened attributes now make possible.
     *
     * Delegated for the same reason the widening is. An administrator edit needs the
     * identical step, and the order combinations come out in decides how they display.
     */
    private function regenerateCombinations(Product $product): void
    {
        $this->variants->regenerateFor($product);
    }

    /**
     * Creates the attachment the proposal was withholding.
     *
     * This is what the whole block was. No attachment row existed while the proposal
     * was pending, and that absence is what stopped the seller selling; approval is
     * where it is finally created, from the listing recorded when they submitted.
     */
    private function releaseAttachment(Proposal $proposal): int
    {
        $variantIds = $proposal->intended_variant_ids ?? [];
        $store = $proposal->store;

        if ($store === null || $variantIds === [] || $proposal->intended_price_minor === null) {
            /*
             * Only reachable for a proposal created before the intended listing was
             * recorded. Logged rather than passed over, because a seller who is
             * unblocked and still not listed has no way to find that out themselves.
             */
            Log::warning('Approved proposal had no intended listing to release.', [
                'proposal_id' => $proposal->id,
            ]);

            return 0;
        }

        $variants = Variant::query()
            ->where('product_id', $proposal->product_id)
            ->whereIn('id', $variantIds)
            ->get();

        $created = 0;

        foreach ($variants as $variant) {
            $attachment = Attachment::firstOrCreate(
                ['store_id' => $store->id, 'variant_id' => $variant->id],
                [
                    'product_id' => $proposal->product_id,
                    'price_minor' => $proposal->intended_price_minor,
                    'currency' => $proposal->intended_currency ?? 'LKR',
                    'is_available' => true,
                ],
            );

            if ($attachment->wasRecentlyCreated) {
                $created++;
            }
        }

        // The model recomputes this on create, so this covers the case where every
        // intended attachment already existed and no create fired.
        $store->recomputeLiveFlag();

        return $created;
    }

    /**
     * The vote endpoint's single entry point (EP-30).
     *
     * Three refusals, checked here rather than in the controller so that the endpoint
     * and any future caller cannot disagree about what a valid vote is:
     *
     *  - **not_eligible_to_vote.** The store is not in the frozen reviewer set. Read
     *    from `proposal_reviewers`, never from current attachments, so a store that
     *    attached mid window cannot vote and one that detached still can.
     *  - **review_closed.** The three day window has run out. The sweep decides it from
     *    here, and a late vote would change an outcome that has already been reached.
     *  - **already_voted.** One vote per store, and a cast vote is never revised.
     *
     * The proposing store is not in its own reviewer set, so it falls out at the first
     * check without needing a rule of its own.
     */
    public function castVote(Proposal $proposal, Store $store, bool $inFavour, ?string $comment): Proposal
    {
        if (! $proposal->hasReviewer($store->id)) {
            throw ApiException::notEligibleToVote();
        }

        if ($proposal->status !== Proposal::STATUS_PENDING || $proposal->reviewHasClosed()) {
            throw ApiException::reviewClosed();
        }

        if ($proposal->votes()->where('store_id', $store->id)->exists()) {
            throw ApiException::alreadyVoted();
        }

        try {
            return $this->recordVote($proposal, $store->id, $inFavour, $comment);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two requests from the same store arriving together both passed the check
             * above before either inserted. The unique index on (proposal_id, store_id)
             * is what actually enforces one vote per store; this turns its error into
             * the same refusal the caller would have got a moment earlier.
             */
            throw ApiException::alreadyVoted();
        }
    }

    /**
     * Records a vote, then resolves if that was the last one outstanding.
     *
     * The guards live in `castVote`, which is what the endpoint calls. Reaching here
     * means the vote has already been established as valid.
     */
    public function recordVote(Proposal $proposal, int $storeId, bool $inFavour, ?string $comment): Proposal
    {
        ProposalVote::create([
            'proposal_id' => $proposal->id,
            'store_id' => $storeId,
            'vote' => $inFavour,
            'comment' => $comment,
        ]);

        return $this->resolveIfReady($proposal);
    }
}
