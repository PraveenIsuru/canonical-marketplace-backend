<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Ai\AiUnavailable;
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
 * front. The platform will eventually make five kinds of call: search interpretation,
 * product matching on text and images, wizard question generation, confidence scoring
 * for confirmation answers, and verification photograph evaluation. The first three
 * exist today. The remaining two are added at M6 and M9, because unimplemented stubs
 * would be dead code that no test exercises and no caller uses.
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
}
