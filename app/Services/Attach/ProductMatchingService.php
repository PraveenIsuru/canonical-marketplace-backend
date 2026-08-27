<?php

declare(strict_types=1);

namespace App\Services\Attach;

use App\Contracts\AiProvider;
use App\Models\Product;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ProductDraft;
use App\Services\Ai\ProductMatchCandidate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Duplicate detection: deciding whether the product a seller is describing is already
 * in the catalogue.
 *
 * This is the gate the whole platform depends on. A product that slips past it becomes
 * a second canonical record for something that already exists, which is precisely the
 * fragmentation the system is built to prevent.
 *
 * Retrieval happens here, in two steps. The database produces a shortlist, then the AI
 * judges it. Splitting the work that way keeps the vendor adapter free of business
 * queries, and it means a provider is never asked to recall the whole catalogue from a
 * prompt, which is not something any model does reliably.
 */
final class ProductMatchingService
{
    /**
     * How many products are put in front of the provider.
     *
     * Large enough that a real duplicate is very unlikely to be missed, small enough
     * that the prompt stays affordable. The shortlist is a recall step: it should be
     * generous, because precision is the provider's job.
     */
    public const SHORTLIST_SIZE = 25;

    public function __construct(private readonly AiProvider $ai) {}

    /**
     * The products that may already be the one being described.
     *
     * An empty result is a real answer, not a failure: it means the catalogue holds
     * nothing like this, and the seller goes to the wizard.
     *
     * @return Collection<int, Product> each carrying a `match_score` attribute
     *
     * @throws AiUnavailable
     */
    public function candidates(ProductDraft $draft): Collection
    {
        $shortlist = $this->shortlist($draft);

        if ($shortlist->isEmpty()) {
            // Nothing was retrieved, so there is nothing for the provider to judge.
            // Calling it anyway would spend money to be told what is already known.
            return new Collection;
        }

        $scored = $this->ai->scoreProductMatches($draft, $shortlist->map(
            static fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category,
            ],
        )->values()->all());

        return $this->resolve($scored, $shortlist);
    }

    /**
     * Candidate products retrieved from the database.
     *
     * Deliberately the database rather than the search index, even though buyer search
     * uses the index. Two reasons.
     *
     * The index is eventually consistent: indexing runs off the request, so a product
     * created moments ago by another seller may not be searchable yet. Missing it here
     * would admit exactly the duplicate this step exists to catch, and the failure
     * would be invisible because the response looks the same either way.
     *
     * The index is also an external service, and this check is too important to stop
     * working when it is unreachable. Buyer search can degrade to keyword results
     * because a worse result list is still useful. Matching cannot degrade at all.
     *
     * @return Collection<int, Product>
     */
    private function shortlist(ProductDraft $draft): Collection
    {
        $words = $this->significantWords($draft->name);

        if ($words === []) {
            return new Collection;
        }

        return Product::query()
            ->where(function (Builder $query) use ($words, $draft): void {
                foreach ($words as $word) {
                    // Case insensitive contains, one term at a time. Matching any word
                    // rather than all of them is intentional: a seller who omits the
                    // brand, or adds a word the record does not use, must still be
                    // shown the record they would otherwise duplicate.
                    $query->orWhere('name', 'ilike', '%'.$this->escapeLike($word).'%');
                }

                if ($draft->category !== null) {
                    $query->orWhere('category', $draft->category);
                }
            })
            ->orderByDesc('id')
            ->limit(self::SHORTLIST_SIZE)
            ->get();
    }

    /**
     * Turns the provider's verdict back into products, in its order.
     *
     * Anything the provider named that was not on the shortlist is dropped rather than
     * looked up. The shortlist is the only set it was asked about, so a product outside
     * it is a mistake, and following it to the database would let a bad reply put an
     * unrelated product in front of a seller.
     *
     * @param  array<int, ProductMatchCandidate>  $scored
     * @param  Collection<int, Product>  $shortlist
     * @return Collection<int, Product>
     */
    private function resolve(array $scored, Collection $shortlist): Collection
    {
        $byId = $shortlist->keyBy('id');
        $candidates = new Collection;

        foreach ($scored as $candidate) {
            $product = $byId->get($candidate->productId);

            if ($product === null) {
                continue;
            }

            // Attached rather than stored. The score describes this comparison, not the
            // product, so it has no column and never outlives the response.
            $product->match_score = round($candidate->score, 2);

            $candidates->push($product);
        }

        return $candidates;
    }

    /**
     * The words worth searching on.
     *
     * Single characters are dropped because a one letter ILIKE matches most of the
     * catalogue and would fill the shortlist with noise, pushing real candidates out.
     *
     * @return array<int, string>
     */
    private function significantWords(string $name): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) > 1,
        )));
    }

    /** Neutralises the LIKE wildcards, so a name containing % or _ searches literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
