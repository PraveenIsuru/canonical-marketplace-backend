<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ProductDraft;
use App\Services\Attach\ProductMatchingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Retries product matching that failed because the provider was unavailable.
 *
 * Matching has no fallback and cannot have one. Buyer search may degrade to keyword
 * results because a worse result list is still useful to a shopper, but a degraded
 * match could let a seller past duplicate detection and create a second canonical
 * record for a product the catalogue already holds. That is the one outcome the whole
 * platform exists to prevent, so the flow blocks and the work is queued instead.
 *
 * The seller polls the job and resumes from its result.
 */
final class MatchProduct implements ShouldQueue
{
    use Queueable;

    /** A slow provider is worth a few attempts; a broken one is not worth many. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly string $aiJobId) {}

    public function handle(ProductMatchingService $matching): void
    {
        $job = AiJob::find($this->aiJobId);

        if ($job === null || $job->status === AiJob::STATUS_COMPLETED) {
            return;
        }

        $job->forceFill(['status' => AiJob::STATUS_PROCESSING])->save();

        /*
         * The image is not retried.
         *
         * An upload submitted for matching is transient and is never stored, so by the
         * time this job runs the file is gone. Text matching on its own is a weaker
         * answer than the seller originally asked for, and saying so in the result is
         * better than either failing outright or pretending the image was considered.
         */
        $draft = ProductDraft::fromArray($job->payload);

        try {
            $candidates = $matching->candidates($draft);
        } catch (AiUnavailable $e) {
            // Rethrown so the queue retries with backoff. failed() records the outcome
            // once the attempts are exhausted.
            $job->forceFill(['status' => AiJob::STATUS_QUEUED])->save();

            throw $e;
        }

        $job->markCompleted([
            'candidates' => $candidates->map(static fn ($product): array => [
                'product_id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'primary_image_url' => $product->images->first()?->url(),
                'match_score' => $product->match_score,
            ])->all(),
            // Stated rather than left to be inferred, so the client can tell the seller
            // their photograph was not part of this answer.
            'image_considered' => false,
        ]);
    }

    /**
     * Records the failure so the client polling this id learns the flow is over.
     *
     * Without this the job would stay queued forever and the interface would poll
     * something that is never going to answer.
     */
    public function failed(?Throwable $e): void
    {
        AiJob::find($this->aiJobId)?->markFailed(
            $e?->getMessage() ?? 'The AI provider could not be reached.',
        );
    }
}
