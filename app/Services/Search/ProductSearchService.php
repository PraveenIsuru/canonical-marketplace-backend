<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\AiProvider;
use App\Models\Product;
use App\Services\Ai\AiUnavailable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Product search, shared by buyer search and seller catalogue search.
 *
 * The two endpoints ask the same question and differ only in what they do when the AI
 * provider fails, so the query itself lives here once. Neither controller decides how
 * to search; they decide how to fail.
 *
 * Interpretation failure is surfaced by throwing, never by silently degrading. Buyer
 * search catches it and falls back; seller search lets it through so the endpoint can
 * queue the work. Swallowing it here would take that decision away from both.
 */
final class ProductSearchService
{
    public const PER_PAGE = 20;

    public function __construct(private readonly AiProvider $ai) {}

    /**
     * Interprets the query with the AI provider, then searches the index.
     *
     * @throws AiUnavailable when the provider cannot answer
     */
    public function interpreted(string $query, ?string $category, int $perPage = self::PER_PAGE): SearchResult
    {
        $interpretation = $this->ai->interpretSearchQuery($query);

        return new SearchResult(
            mode: SearchMode::Ai,
            // The original query, not the interpreted terms, is what the pagination
            // links carry. A visitor following page two should re-run what they typed.
            results: $this->runQuery($interpretation->terms, $category, $perPage, $query),
        );
    }

    /**
     * Searches the index with the raw query, no interpretation.
     *
     * This is the availability floor for buyer discovery. A buyer arriving here has
     * typed a natural language question, so the engine receives phrasing it was not
     * designed for and still has to return something useful. Meilisearch's typo
     * tolerance and relevance ranking are what make that acceptable.
     */
    public function keyword(string $query, ?string $category, int $perPage = self::PER_PAGE): SearchResult
    {
        return new SearchResult(
            mode: SearchMode::Keyword,
            results: $this->runQuery($query, $category, $perPage, $query),
        );
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function runQuery(
        string $terms,
        ?string $category,
        int $perPage,
        string $originalQuery,
    ): LengthAwarePaginator {
        $search = Product::search($terms)
            /*
             * Eager loaded through Scout's own query callback. The catalogue resource
             * reads the primary image for every hit, so without this a twenty result
             * page issues twenty extra queries.
             */
            ->query(fn ($query) => $query->with('images'));

        if ($category !== null) {
            // Filtered inside the engine rather than in PHP, which is why `category` is
            // declared filterable in the Meilisearch index settings.
            $search->where('category', $category);
        }

        $paginator = $search->paginate($perPage);

        /*
         * Scout appends its own `query` parameter to the pagination links, but this
         * endpoint takes `q`. Left alone, following a "next page" link would drop the
         * search entirely and return the whole catalogue.
         */
        return $paginator->appends([
            // Null drops Scout's own parameter: http_build_query skips null values, so
            // the link carries only the name this endpoint actually reads.
            'query' => null,
            'q' => $originalQuery,
            'category' => $category,
        ]);
    }
}
