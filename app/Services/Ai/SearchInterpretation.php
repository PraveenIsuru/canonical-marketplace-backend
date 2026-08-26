<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * What the AI made of a buyer's query.
 *
 * `terms` is what gets handed to the search index. `category` is an optional narrowing
 * the AI inferred, which the caller may apply on top of any category the buyer chose
 * explicitly; an explicit choice always wins.
 */
final readonly class SearchInterpretation
{
    /**
     * @param  array<int, string>  $keywords
     */
    public function __construct(
        public string $terms,
        public array $keywords = [],
        public ?string $category = null,
    ) {}
}
