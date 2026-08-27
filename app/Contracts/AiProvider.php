<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Product;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ConfidenceAssessment;
use App\Services\Ai\ConfirmationQuestion;
use App\Services\Ai\OwnershipAssessment;
use App\Services\Ai\ProductDraft;
use App\Services\Ai\ProductMatchCandidate;
use App\Services\Ai\SearchInterpretation;
use App\Services\Ai\WizardQuestion;

/**
 * The platform's access to an AI provider.
 *
 * Every AI call goes through this interface so the provider can be switched on cost
 * grounds without touching any calling code. Vendor SDKs live only in adapters; no
 * feature ever imports one.
 *
 * The interface grows one method per milestone rather than being declared whole up
 * front. The platform makes five kinds of call: search interpretation, product
 * matching on text and images, wizard question generation, confirmation questions with
 * the confidence scoring that follows them, and verification photograph evaluation.
 * All but the last exist today; verification is added at M9, because an unimplemented
 * stub would be dead code that no test exercises and no caller uses.
 *
 * Note that two of the methods take image input, which constrains provider
 * substitution to vision capable models. That is documented here rather than hidden.
 *
 * Every method throws AiUnavailable rather than returning a degraded result. Callers
 * respond to failure differently, and swallowing it here would take that decision away
 * from all of them.
 */
interface AiProvider
{
    /**
     * Interpret a buyer's natural language query into search terms.
     *
     * Implementations must throw AiUnavailable rather than returning a degraded result,
     * because the two callers respond to failure in opposite ways: buyer search falls
     * back to keyword results, and seller catalogue search queues the work. Swallowing
     * the failure here would take that decision away from both of them.
     *
     * @throws AiUnavailable
     */
    public function interpretSearchQuery(string $query): SearchInterpretation;

    /**
     * Judge which of a shortlist of existing products the seller's draft describes.
     *
     * The retrieval is done by the application, not here. The catalogue is searched
     * first and the results are handed over to be judged, which is why this method
     * takes candidates rather than going looking for them. Two reasons: an adapter that
     * queried the database would be a vendor class holding a business query, and asking
     * a provider to recall the whole catalogue from a prompt is not something any model
     * can do reliably.
     *
     * Returning fewer candidates than were supplied is the normal case, and returning
     * none is a meaningful answer: it means no existing record matches, and the seller
     * goes to the wizard. It is not a failure and must not be reported as one.
     *
     * @param  array<int, array{id: int, name: string, description: string|null, category: string}>  $shortlist
     * @return array<int, ProductMatchCandidate> ordered by descending score
     *
     * @throws AiUnavailable
     */
    public function scoreProductMatches(ProductDraft $draft, array $shortlist): array;

    /**
     * Generate the questions the listing wizard puts to a seller.
     *
     * Questions are written from a buyer's perspective: what someone shopping for this
     * product would want to know, rather than what a catalogue schema would ask for.
     * That is the point of using a provider here at all, since a fixed question set
     * could not adapt to what the product actually is.
     *
     * @return array<int, WizardQuestion>
     *
     * @throws AiUnavailable
     */
    public function generateWizardQuestions(ProductDraft $draft): array;

    /**
     * Generate the questions put to a seller attaching to a record that already exists.
     *
     * **Every attribute on the record is questioned, every time, without exception.**
     * Nothing is ever treated as settled: a seller who appears to be attaching may in
     * fact be describing a variant the record does not hold, and the only way to find
     * that out is to ask about everything and compare the answers.
     *
     * The product is passed whole because the questions have to cover its core fields,
     * every specification key, and every variant attribute. An implementation that
     * covered only some of them would silently narrow the flow.
     *
     * @return array<int, ConfirmationQuestion>
     *
     * @throws AiUnavailable
     */
    public function generateConfirmationQuestions(Product $product): array;

    /**
     * Score how well a seller's confirmation answers hold together.
     *
     * **The seller never supplies this and never sees it.** It is scored from the
     * content and consistency of what they wrote, written to the proposal, and used to
     * decide the resolution matrix at M7. It appears in no response body anywhere.
     *
     * Implementations judge the answers, not whether they agree with the record.
     * Disagreement is exactly what a proposal is for, and scoring it down would make
     * the platform prefer sellers who repeat back what it already believes.
     *
     * @param  array<int, ConfirmationQuestion>  $questions
     * @param  array<string, string>  $answers  keyed by question id
     *
     * @throws AiUnavailable
     */
    public function scoreConfirmationAnswers(array $questions, array $answers): ConfidenceAssessment;

    /**
     * Judge whether a photograph shows this product beside the code we issued.
     *
     * The code is what makes the photograph evidence of **present possession** rather
     * than an image found online, so an implementation must check both halves: that the
     * code is legible and correct, and that the product in frame is this product.
     * Passing on the code alone would verify a handwritten note.
     *
     * The photograph is passed as raw bytes rather than a path, deliberately. Nothing
     * downstream of this call needs to know where the file lived, and a method taking a
     * path invites an implementation that keeps one.
     *
     * The caller deletes the photograph the moment this returns, on a pass and on a
     * failure alike, so an implementation must not retain it.
     *
     * @param  string  $photo  raw image bytes
     * @param  string  $mimeType  the image's media type, for the vision request
     *
     * @throws AiUnavailable
     */
    public function verifyOwnership(
        Product $product,
        string $code,
        string $photo,
        string $mimeType,
    ): OwnershipAssessment;

    /**
     * Summarise what buyers are saying about a product.
     *
     * Reads the discussion and reports the shape of it: what owners agree on, what they
     * disagree about, and what recurs. It is regenerated periodically rather than on
     * every post, so it must read as a standing description rather than as a reply to
     * the newest comment.
     *
     * It is **not** a rating and must not produce one. The platform has no star score
     * and no sentiment number anywhere, and reducing a discussion to a figure would
     * invite exactly the comparison the canonical catalogue exists to avoid.
     *
     * @param  array<int, string>  $posts  post bodies, newest last
     *
     * @throws AiUnavailable
     */
    public function summariseCommunity(Product $product, array $posts): string;
}
