<?php

declare(strict_types=1);

namespace App\Services\Attach;

use App\Contracts\AiProvider;
use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\AttachSession;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Store;
use App\Models\Variant;
use App\Notifications\ProposalNeedsReview;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ConfirmationQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The confirmation flow: a seller joining a record that already exists.
 *
 * The heart of the platform, and the reason it is not just a listings site. A seller
 * cannot write to a canonical record, so the only way their knowledge reaches it is by
 * answering questions about the product and having the differences reviewed by the
 * other sellers who carry it.
 *
 * Three rules run through everything here and none of them may be relaxed:
 *
 *  - **Every question is answered, or nothing happens.** Completion is mandatory.
 *  - **No attachment exists while a proposal is pending.** The missing row *is* the
 *    block on the proposing seller, not a flag beside it.
 *  - **The confidence score never leaves the server.** It is written to the proposal
 *    and read at resolution, and no response body carries it.
 */
final class ConfirmationService
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly RecordComparison $comparison,
    ) {}

    /**
     * Opens a confirmation session and returns the questions.
     *
     * Refuses before spending a provider call in the two cases where the flow could
     * only end in refusal anyway: the seller already carries this product, or they have
     * a proposal on it still being reviewed.
     *
     * @throws AiUnavailable when the provider cannot answer
     */
    public function start(Store $store, Product $product): AttachSession
    {
        $this->assertNotBlocked($store, $product);

        // Loaded fresh so the questions describe the record as it is now, not as some
        // caller happened to have it in memory.
        $product->load('productAttributes');

        $questions = $this->ai->generateConfirmationQuestions($product);

        return AttachSession::create([
            'store_id' => $store->id,
            'type' => AttachSession::TYPE_CONFIRMATION,
            // Set, unlike a wizard session. This flow questions a record that exists.
            'product_id' => $product->id,
            'questions' => array_map(
                static fn (ConfirmationQuestion $question): array => $question->toArray(),
                $questions,
            ),
            'draft' => [],
            'expires_at' => now()->addHours(AttachSession::LIFETIME_HOURS),
        ]);
    }

    /**
     * Submits the answers, and either attaches the seller or opens a proposal.
     *
     * One transaction. The proposal path writes the proposal, freezes its reviewer set,
     * and consumes the session; the attach path writes attachments and consumes the
     * session. A half completed either would leave a seller unable to act and unable to
     * find out why.
     *
     * @param  array<string, string>  $answers
     * @param  array<int, int>  $variantIds
     *
     * @throws AiUnavailable when the provider cannot score the answers
     */
    public function submit(
        Store $store,
        AttachSession $session,
        array $answers,
        array $variantIds,
        int $priceMinor,
        string $currency,
    ): ConfirmationOutcome {
        $product = $session->product;

        if ($product === null) {
            throw ApiException::notFound('That confirmation session has no product.');
        }

        // Re-checked at submit, not only at start. A seller can hold a session open
        // while something else changes underneath it, and the guard that matters is the
        // one closest to the write.
        $this->assertNotBlocked($store, $product);

        $questions = $this->questionsOf($session);

        $this->assertEveryQuestionAnswered($questions, $answers);

        $variants = $this->resolveVariants($product, $variantIds);

        /*
         * Scored before the transaction opens.
         *
         * This is the one call here that can fail for reasons outside the platform, and
         * a provider timeout inside a transaction would hold locks on the attachments
         * table for as long as the vendor took to not answer.
         */
        $assessment = $this->ai->scoreConfirmationAnswers($questions, $answers);

        $changes = $this->comparison->differences($questions, $answers);

        return DB::transaction(function () use (
            $store, $product, $session, $questions, $answers, $variants, $priceMinor, $currency, $assessment, $changes
        ): ConfirmationOutcome {
            $outcome = $changes === []
                ? $this->attach($store, $product, $variants, $priceMinor, $currency)
                : $this->openProposal($store, $product, $changes, $answers, $questions, $assessment->score);

            // Consumed either way. A session that could be submitted twice would create
            // a second proposal or a duplicate attachment.
            $session->delete();

            return $outcome;
        });
    }

    /**
     * The attach path: the seller described the record as it already is.
     *
     * Nothing about the product changes, no version is created, and no review happens,
     * because there is nothing to review. The store becomes visible to buyers.
     *
     * @param  Collection<int, Variant>  $variants
     */
    private function attach(
        Store $store,
        Product $product,
        Collection $variants,
        int $priceMinor,
        string $currency,
    ): ConfirmationOutcome {
        $attachments = $variants->map(fn (Variant $variant): Attachment => Attachment::create([
            'store_id' => $store->id,
            'variant_id' => $variant->id,
            // Denormalised deliberately, so the seller list query on the busiest page
            // in the system does not join variants on every request.
            'product_id' => $product->id,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'is_available' => true,
        ]));

        // The model already recomputes this on create, so this is belt and braces
        // against a future path that writes attachments some other way.
        $store->recomputeLiveFlag();

        return ConfirmationOutcome::attached($attachments);
    }

    /**
     * The proposal path: the seller described something the record does not say.
     *
     * **No attachment is created here, and that is the entire mechanism.** The seller
     * is blocked from selling this product until the proposal resolves, and the block
     * is expressed by the absence of a row rather than by a flag that some other query
     * might forget to check.
     *
     * @param  array<string, array{from: string|null, to: string}>  $changes
     * @param  array<string, string>  $answers
     * @param  array<int, ConfirmationQuestion>  $questions
     */
    private function openProposal(
        Store $store,
        Product $product,
        array $changes,
        array $answers,
        array $questions,
        float $score,
    ): ConfirmationOutcome {
        $opensAt = now();

        $proposal = Proposal::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'changes' => $changes,
            // The raw answers are retained as evidence, so a reviewer at M7 and an
            // administrator at M11 can see what was actually said rather than only the
            // diff it produced.
            'ai_answers' => $this->transcript($questions, $answers),
            'confidence_score' => $score,
            'confidence_band' => Proposal::bandFor($score),
            'status' => Proposal::STATUS_PENDING,
            'review_opens_at' => $opensAt,
            // Exactly three days. Fixed platform wide, not configurable per product.
            'review_closes_at' => $opensAt->addDays(Proposal::REVIEW_WINDOW_DAYS),
        ]);

        $this->freezeReviewers($proposal, $product, $store);

        return ConfirmationOutcome::proposalCreated($proposal);
    }

    /**
     * Records who may vote, once, at the moment the proposal opens.
     *
     * Written down rather than derived later, because it cannot be derived later.
     * Attachments change during the three day window: a store attaching on day two
     * would look eligible to any query run on day three, and a store that detaches
     * would look ineligible even though its vote must stand. Neither is recoverable
     * once the moment has passed.
     *
     * The proposing store is excluded. A seller voting on their own proposal would be
     * deciding their own case, and where they are the only other attached store the
     * vote would be unanimous by construction.
     */
    private function freezeReviewers(Proposal $proposal, Product $product, Store $proposingStore): void
    {
        $storeIds = Attachment::query()
            ->where('product_id', $product->id)
            ->where('store_id', '!=', $proposingStore->id)
            ->distinct()
            ->pluck('store_id');

        foreach ($storeIds as $storeId) {
            ProposalReviewer::create([
                'proposal_id' => $proposal->id,
                'store_id' => $storeId,
                'notified_at' => now(),
            ]);
        }

        $this->notifyReviewers($proposal, $storeIds->all());
    }

    /**
     * Emails every frozen reviewer.
     *
     * Dispatched after the transaction commits, so a mail failure cannot roll back a
     * proposal that is otherwise correct and whose review window has already begun.
     * Email is the only notification surface this platform has, so a proposal nobody
     * was told about simply escalates for want of votes.
     *
     * @param  array<int, int>  $storeIds
     */
    private function notifyReviewers(Proposal $proposal, array $storeIds): void
    {
        if ($storeIds === []) {
            /*
             * A product with exactly one attached seller who is also the proposer has
             * no reviewers at all. That is a real state, not a bug: the proposal will
             * reach its closing time with no votes and escalate to an administrator,
             * which is the defined outcome for an unreviewed proposal.
             */
            return;
        }

        DB::afterCommit(function () use ($proposal, $storeIds): void {
            $recipients = Store::with('user')
                ->whereIn('id', $storeIds)
                ->get()
                ->pluck('user')
                ->filter();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new ProposalNeedsReview($proposal));
            }
        });
    }

    /**
     * Refuses the two states in which this seller cannot attach to this product.
     *
     * `proposal_pending` is checked first. A seller with a proposal under review has no
     * attachment by design, so checking attachment first would report the more
     * confusing of the two refusals.
     */
    private function assertNotBlocked(Store $store, Product $product): void
    {
        $hasPendingProposal = Proposal::query()
            ->blocking()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->exists();

        if ($hasPendingProposal) {
            throw ApiException::proposalPending();
        }

        $alreadyCarries = Attachment::query()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->exists();

        if ($alreadyCarries) {
            throw ApiException::alreadyAttached();
        }
    }

    /**
     * Every question this session asked must carry a non empty answer.
     *
     * Checked against the stored questions, never against what the client sent. A
     * client supplying both the questions and the answers could always report itself
     * complete, which would make the mandatory flow optional in practice.
     *
     * @param  array<int, ConfirmationQuestion>  $questions
     * @param  array<string, string>  $answers
     */
    private function assertEveryQuestionAnswered(array $questions, array $answers): void
    {
        foreach ($questions as $question) {
            $answer = $answers[$question->id] ?? null;

            if (! is_string($answer) || trim($answer) === '') {
                // Its own registered code, not validation_failed. The client shows a
                // different screen for an incomplete confirmation than for a malformed
                // request, and the flow cannot be skipped or partially completed.
                throw ApiException::confirmationIncomplete();
            }
        }
    }

    /**
     * The chosen variants, confirmed to belong to this product.
     *
     * A variant id from another product would attach the seller to something they never
     * saw, so an unknown id is a refusal rather than something to filter out quietly.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, Variant>
     */
    private function resolveVariants(Product $product, array $variantIds): Collection
    {
        $variants = Variant::query()
            ->where('product_id', $product->id)
            ->whereIn('id', $variantIds)
            ->get();

        if ($variants->count() !== count(array_unique($variantIds))) {
            throw new ApiException(422, 'validation_failed', 'The given data was invalid.', [
                'variant_ids' => ['Every version must be one this product actually has.'],
            ]);
        }

        return $variants;
    }

    /**
     * The session's questions, back as objects.
     *
     * @return array<int, ConfirmationQuestion>
     */
    private function questionsOf(AttachSession $session): array
    {
        return array_map(
            static fn (array $question): ConfirmationQuestion => ConfirmationQuestion::fromArray($question),
            $session->questions,
        );
    }

    /**
     * The answers as evidence, paired with what was asked.
     *
     * Stored rather than the bare answer map, because `{"q3": "192"}` means nothing
     * once the session is gone, and a reviewer reading a proposal months later needs
     * to know what question produced it.
     *
     * @param  array<int, ConfirmationQuestion>  $questions
     * @param  array<string, string>  $answers
     * @return array<int, array<string, string|null>>
     */
    private function transcript(array $questions, array $answers): array
    {
        return array_map(
            static fn (ConfirmationQuestion $question): array => [
                'attribute' => $question->attribute,
                'question' => $question->text,
                'answer' => trim($answers[$question->id] ?? ''),
                'previous_value' => $question->currentValue,
            ],
            $questions,
        );
    }
}
