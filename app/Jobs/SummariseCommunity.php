<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiProvider;
use App\Models\CommunityPost;
use App\Models\CommunitySummary;
use App\Models\Product;
use App\Services\Ai\AiUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Regenerates a product's discussion summary.
 *
 * Read by EP-53, which has existed since M2 and returned null all this time because
 * nothing wrote the row. This is what writes it. The public shape is unchanged.
 *
 * **Nothing waits on this.** It is not queued from a request and no screen blocks on
 * it: a product with no summary yet shows no summary, which is a perfectly good state.
 * That is why a provider failure here simply leaves the previous summary in place
 * rather than returning 503 anywhere. There is no user in the loop to tell.
 *
 * It is emphatically **not a rating**. The platform has no star score and no sentiment
 * number, and the provider is told so directly, because a model left to itself will
 * reach for one.
 */
final class SummariseCommunity implements ShouldQueue
{
    use Queueable;

    /** Enough posts to describe a discussion without paying for the whole archive. */
    private const SAMPLE_SIZE = 100;

    /** Below this a summary says less than the posts themselves do. */
    private const MINIMUM_POSTS = 3;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30];

    public function __construct(public readonly int $productId) {}

    public function handle(AiProvider $ai): void
    {
        $product = Product::find($this->productId);

        if ($product === null) {
            return;
        }

        $posts = CommunityPost::query()
            ->where('product_id', $product->id)
            ->orderByDesc('created_at')
            ->limit(self::SAMPLE_SIZE)
            ->pluck('body')
            ->all();

        if (count($posts) < self::MINIMUM_POSTS) {
            /*
             * Two comments do not have a shape to describe, and summarising them would
             * produce a sentence longer than the thing it summarises. The screen shows
             * the posts instead, which is better anyway.
             */
            return;
        }

        // Oldest first, so the provider reads the discussion in the order it happened.
        $posts = array_reverse($posts);

        try {
            $summary = $ai->summariseCommunity($product, $posts);
        } catch (AiUnavailable) {
            /*
             * Left for the next run. Nobody is waiting on this, and yesterday's summary
             * is a better answer than none, so the previous row stays exactly as it is.
             */
            return;
        }

        if (trim($summary) === '') {
            return;
        }

        CommunitySummary::updateOrCreate(
            ['product_id' => $product->id],
            [
                'summary_text' => $summary,
                // Recorded so a later run can judge whether regenerating is worth a
                // provider call, rather than rewriting an unchanged discussion.
                'post_count_at_generation' => count($posts),
                'generated_at' => now(),
            ],
        );
    }
}
