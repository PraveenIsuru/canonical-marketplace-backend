<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProvider;

/**
 * The AI provider used in development and in every test.
 *
 * It exists so the whole platform can be built and tested without a network call or a
 * provider bill, and so the failure path is exercisable on demand.
 *
 * The failing mode is not a convenience. Buyer search degrading to keyword results and
 * seller search returning 503 are opposite behaviours that both hang off a provider
 * failure, and neither can be tested honestly by mocking the caller. Forcing this
 * adapter to fail is the only way to prove the two endpoints really do diverge.
 */
final class FakeAiProvider implements AiProvider
{
    public function __construct(private readonly bool $shouldFail = false) {}

    /**
     * Interpretation is deliberately shallow: it strips the filler words a person puts
     * in a spoken question so the remaining terms hit the index.
     *
     * It is not trying to be a good interpreter. Its job is to be a *different* result
     * from the raw query, so a test can tell which path served a response by looking at
     * what came back rather than trusting the mode field to be honest.
     */
    public function interpretSearchQuery(string $query): SearchInterpretation
    {
        if ($this->shouldFail) {
            throw AiUnavailable::because('the fake provider is configured to fail');
        }

        $stopWords = [
            'a', 'an', 'the', 'is', 'am', 'are', 'was', 'were', 'be', 'been',
            'i', 'me', 'my', 'we', 'you', 'it', 'its',
            'want', 'wanted', 'need', 'needed', 'looking', 'look', 'for', 'find',
            'show', 'get', 'buy', 'like', 'would', 'please',
            'with', 'that', 'this', 'there', 'here', 'has', 'have', 'had', 'can',
            'good', 'best', 'cheap', 'nice', 'some', 'any',
            'and', 'or', 'of', 'in', 'on', 'at', 'to',
        ];

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keywords = array_values(array_diff($words, $stopWords));

        // Everything was filler, so there is nothing to narrow with. Handing the index
        // an empty string would return the whole catalogue as though it matched.
        if ($keywords === []) {
            $keywords = $words;
        }

        return new SearchInterpretation(
            terms: implode(' ', $keywords),
            keywords: $keywords,
            category: null,
        );
    }
}
