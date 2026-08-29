<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * How one vendor is spoken to.
 *
 * Deliberately not in app/Contracts. The interfaces there are the seams features inject
 * and the container binds by name; this one is an implementation detail of
 * PromptedAiProvider and is built inline where the provider is chosen. Advertising it
 * alongside AiProvider would invite a feature to inject a transport directly and send
 * its own prompt, which is exactly what having one prompt class prevents.
 *
 * Every request carried here asks for a JSON object. An implementation may tell its
 * vendor so in whatever way that vendor supports, or not at all.
 */
interface AiTransport
{
    /**
     * One request, one reply, as the model wrote it.
     *
     * Returns the raw text and nothing else. Whether that text is the JSON we asked for
     * is a judgement about the answer rather than about the wire, so it is made once in
     * PromptedAiProvider instead of once per vendor.
     *
     * @throws AiUnavailable
     */
    public function ask(AiRequest $request): string;
}
