<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Ai\AiUnavailable;
use App\Services\Ai\SearchInterpretation;

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
 * for confirmation answers, and verification photograph evaluation. Only the first
 * exists today, because four unimplemented stubs would be dead code that no test
 * exercises and no caller uses.
 *
 * Note that two of the coming methods take image input, which constrains provider
 * substitution to vision capable models. That is documented here rather than hidden.
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
}
