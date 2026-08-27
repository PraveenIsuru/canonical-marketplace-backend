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
 *
 * None of the answers here are clever. Each one aims to be *decidably different* from
 * its input, so a test can tell which path produced a result by looking at what came
 * back rather than trusting a mode field to be honest about it.
 */
final class FakeAiProvider implements AiProvider
{
    /**
     * Above this, the draft and the candidate are taken to be the same product.
     *
     * Set where it is so the demonstration works both ways round: an almost identical
     * name clears it and sends the seller to confirmation, and a genuinely new product
     * clears nothing and sends them to the wizard.
     */
    private const MATCH_THRESHOLD = 0.45;

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

        $keywords = array_values(array_diff($this->words($query), self::STOP_WORDS));

        // Everything was filler, so there is nothing to narrow with. Handing the index
        // an empty string would return the whole catalogue as though it matched.
        if ($keywords === []) {
            $keywords = $this->words($query);
        }

        return new SearchInterpretation(
            terms: implode(' ', $keywords),
            keywords: $keywords,
            category: null,
        );
    }

    /**
     * Scores each candidate on how much of its name the draft shares, and drops the
     * ones that fall short.
     *
     * A real provider would weigh the description, the category, and the image. This
     * weighs names, because the behaviour under test is not the quality of the match.
     * It is what the platform does with an empty result versus a populated one, and a
     * crude score exercises both ends of that reliably.
     *
     * The image is ignored rather than pretended about. Claiming to have looked at it
     * would make a test asserting image matching pass while proving nothing.
     */
    public function scoreProductMatches(ProductDraft $draft, array $shortlist): array
    {
        if ($this->shouldFail) {
            throw AiUnavailable::because('the fake provider is configured to fail');
        }

        $candidates = [];

        foreach ($shortlist as $product) {
            $score = $this->similarity($draft->name, $product['name']);

            if ($score >= self::MATCH_THRESHOLD) {
                $candidates[] = new ProductMatchCandidate($product['id'], $score);
            }
        }

        usort($candidates, static fn (ProductMatchCandidate $a, ProductMatchCandidate $b): int => $b->score <=> $a->score);

        return $candidates;
    }

    /**
     * Produces one buyer style question per generic topic, plus one naming the product.
     *
     * A real provider would decide which topics suit this particular product. This one
     * asks the same set every time, which is enough for the wizard to be built and
     * tested against, and is honest about being a stand in.
     */
    public function generateWizardQuestions(ProductDraft $draft): array
    {
        if ($this->shouldFail) {
            throw AiUnavailable::because('the fake provider is configured to fail');
        }

        $topics = [
            ['brand', "Who makes {$draft->name}, and is the brand printed on the product itself?"],
            ['model', 'What is the exact model name or number, as printed on the box?'],
            ['key_features', 'What would a buyer most want to know about this product before choosing it?'],
            ['materials', 'What is it made of, and does that differ between versions?'],
            ['dimensions', 'What size is it, in the units a buyer would expect to see?'],
            ['in_the_box', 'What does a buyer receive in the box?'],
        ];

        $questions = [];

        foreach ($topics as $index => [$attribute, $text]) {
            // Ids are positional and stable for the life of the session, because the
            // client sends its answers back keyed by them.
            $questions[] = new WizardQuestion('q'.($index + 1), $attribute, $text);
        }

        return $questions;
    }

    /**
     * The share of the shorter name's words that also appear in the longer one.
     *
     * Overlap rather than edit distance, so word order does not matter: a seller typing
     * "Scarlett Solo 4th Gen Focusrite" should match "Focusrite Scarlett Solo 4th Gen".
     */
    private function similarity(string $left, string $right): float
    {
        $leftWords = array_unique($this->words($left));
        $rightWords = array_unique($this->words($right));

        if ($leftWords === [] || $rightWords === []) {
            return 0.0;
        }

        $shared = count(array_intersect($leftWords, $rightWords));

        return $shared / min(count($leftWords), count($rightWords));
    }

    /** @return array<int, string> */
    private function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @var array<int, string> */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'is', 'am', 'are', 'was', 'were', 'be', 'been',
        'i', 'me', 'my', 'we', 'you', 'it', 'its',
        'want', 'wanted', 'need', 'needed', 'looking', 'look', 'for', 'find',
        'show', 'get', 'buy', 'like', 'would', 'please',
        'with', 'that', 'this', 'there', 'here', 'has', 'have', 'had', 'can',
        'good', 'best', 'cheap', 'nice', 'some', 'any',
        'and', 'or', 'of', 'in', 'on', 'at', 'to',
    ];
}
