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
     */
    private function applyApproval(Proposal $proposal): void
    {
        $product = $proposal->product;

        $this->applyChanges($product, $proposal->changes);

        // A version exists for an accepted proposal and an administrator edit, and for
        // nothing else. A rejected proposal never reaches here.
        $this->versions->record(
            $product->refresh(),
            causedByStore: $proposal->store,
            causedByUser: $proposal->store?->user,
            proposalId: $proposal->id,
        );

        $this->releaseAttachment($proposal);

        // Dispatched after the surrounding transaction commits, so the index never
        // advertises a record that then rolled back.
        DB::afterCommit(fn () => IndexProduct::dispatch($product->id));
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
     * Widens an attribute's option list.
     *
     * **Additive only.** Options present on the record are kept even when the seller
     * did not list them, because a combination generated from an option is permanent,
     * and removing the option would leave combinations referring to a value the record
     * no longer claims to have.
     *
     * So a seller proposing "Black, Sand" against a record holding "Black, Grey" adds
     * Sand and keeps Grey. They have told us about a version we did not know about,
     * not told us the one we knew about stopped existing.
     */
    private function applyAttributeOptions(ProductAttribute $definition, string $proposed): void
    {
        $submitted = array_values(array_filter(array_map(
            static fn (string $option): string => trim($option),
            explode(',', $proposed),
        )));

        $existing = $definition->options;
        $merged = $existing;

        foreach ($submitted as $option) {
            $alreadyPresent = array_filter(
                $existing,
                static fn (string $current): bool => mb_strtolower($current) === mb_strtolower($option),
            );

            if ($alreadyPresent === []) {
                $merged[] = $option;
            }
        }

        $definition->options = array_values($merged);
        $definition->save();
    }

    /**
     * Generates any combinations the widened attributes now make possible.
     *
     * Additive, and it leaves every existing combination and every existing attachment
     * untouched. A seller carrying "Black, Medium" keeps carrying exactly that when a
     * new colour appears.
     */
    private function regenerateCombinations(Product $product): void
    {
        $attributes = $product->productAttributes()->orderBy('position')->get()
            ->map(static fn (ProductAttribute $attribute): array => [
                'name' => $attribute->name,
                'options' => $attribute->options,
            ])
            ->all();

        $this->variants->generateFor($product, $this->variants->combinations($attributes));
    }

    /**
     * Creates the attachment the proposal was withholding.
     *
     * This is what the whole block was. No attachment row existed while the proposal
     * was pending, and that absence is what stopped the seller selling; approval is
     * where it is finally created, from the listing recorded when they submitted.
     */
    private function releaseAttachment(Proposal $proposal): void
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

            return;
        }

        $variants = Variant::query()
            ->where('product_id', $proposal->product_id)
            ->whereIn('id', $variantIds)
            ->get();

        foreach ($variants as $variant) {
            Attachment::firstOrCreate(
                ['store_id' => $store->id, 'variant_id' => $variant->id],
                [
                    'product_id' => $proposal->product_id,
                    'price_minor' => $proposal->intended_price_minor,
                    'currency' => $proposal->intended_currency ?? 'LKR',
                    'is_available' => true,
                ],
            );
        }

        // The model recomputes this on create, so this covers the case where every
        // intended attachment already existed and no create fired.
        $store->recomputeLiveFlag();
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
