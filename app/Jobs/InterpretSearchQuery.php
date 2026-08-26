<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiProvider;
use App\Models\AiJob;
use App\Services\Ai\AiUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Retries a search interpretation that failed because the provider was unavailable.
 *
 * Queued only by seller catalogue search. Buyer search never reaches here: it falls
 * back to keyword results, because search is the availability floor for discovery and
 * a buyer should never be made to wait for a provider.
 *
 * Seller search cannot do the same. It feeds duplicate detection, and a degraded
 * keyword result could let a seller past the check and admit a second canonical record
 * for a product that already exists, which is precisely what the platform exists to
 * prevent.
 */
final class InterpretSearchQuery implements ShouldQueue
{
    use Queueable;

    /** A slow provider is worth a few attempts; a broken one is not worth many. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly string $aiJobId) {}

    public function handle(AiProvider $ai): void
    {
        $job = AiJob::find($this->aiJobId);

        if ($job === null || $job->status === AiJob::STATUS_COMPLETED) {
            return;
        }

        $job->forceFill(['status' => AiJob::STATUS_PROCESSING])->save();

        try {
            $interpretation = $ai->interpretSearchQuery((string) ($job->payload['query'] ?? ''));
        } catch (AiUnavailable $e) {
            // Rethrown so the queue retries with backoff. failed() records the outcome
            // once the attempts are exhausted.
            $job->forceFill(['status' => AiJob::STATUS_QUEUED])->save();

            throw $e;
        }

        $job->markCompleted([
            'terms' => $interpretation->terms,
            'keywords' => $interpretation->keywords,
            'category' => $interpretation->category,
        ]);
    }

    /**
     * Records the failure so the client polling this id learns the flow is over.
     *
     * Without this the job would stay "queued" forever and the interface would poll
     * something that is never going to answer.
     */
    public function failed(?Throwable $e): void
    {
        AiJob::find($this->aiJobId)?->markFailed(
            $e?->getMessage() ?? 'The AI provider could not be reached.',
        );
    }
}
