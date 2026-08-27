<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\AttachSession;
use App\Models\Store;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ProductDraft;
use App\Services\Attach\ProductWizardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Retries wizard question generation that failed because the provider was unavailable.
 *
 * The job opens the session itself once the provider answers, rather than handing the
 * questions back for the client to open one with. The seller may well have closed the
 * browser by then, and a session that only exists if someone was watching would lose
 * the flow exactly when the recovery path is supposed to save it.
 *
 * The result carries the session id, so a returning seller resumes rather than starts
 * over.
 */
final class GenerateWizardQuestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly string $aiJobId) {}

    public function handle(ProductWizardService $wizard): void
    {
        $job = AiJob::find($this->aiJobId);

        if ($job === null || $job->status === AiJob::STATUS_COMPLETED) {
            return;
        }

        $store = Store::find((int) ($job->payload['store_id'] ?? 0));

        if ($store === null) {
            // The store went away while the work was queued. There is nobody to open a
            // session for, and retrying will not bring it back.
            $job->markFailed('The store this wizard belonged to no longer exists.');

            return;
        }

        $job->forceFill(['status' => AiJob::STATUS_PROCESSING])->save();

        try {
            $session = $wizard->startSession($store, ProductDraft::fromArray($job->payload));
        } catch (AiUnavailable $e) {
            $job->forceFill(['status' => AiJob::STATUS_QUEUED])->save();

            throw $e;
        }

        $job->markCompleted($this->resultFrom($session));
    }

    /**
     * The same shape EP-23 returns, so a client resuming from a job result runs the
     * identical code path it would have run had the provider answered first time.
     *
     * @return array<string, mixed>
     */
    private function resultFrom(AttachSession $session): array
    {
        return [
            'session_id' => $session->id,
            'questions' => $session->questions,
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }

    public function failed(?Throwable $e): void
    {
        AiJob::find($this->aiJobId)?->markFailed(
            $e?->getMessage() ?? 'The AI provider could not be reached.',
        );
    }
}
