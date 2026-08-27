<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\AiJob;
use App\Models\AttachSession;
use App\Models\Store;
use App\Services\Ai\AiUnavailable;
use App\Services\Attach\ConfirmationOutcome;
use App\Services\Attach\ConfirmationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Finishes a confirmation submission that blocked because the provider was unavailable.
 *
 * The submission is not lost when the AI cannot score it. The answers are already on
 * the session, so this job re-runs the whole submit once the provider recovers and the
 * seller polls the result.
 *
 * It completes the **entire** submission rather than only the scoring, and that is
 * deliberate. Scoring alone would leave the write to happen later on some other request
 * that might never come, and the seller would be told their submission was saved while
 * nothing had been decided.
 *
 * Confidence never appears in the result. What the client resumes from is the outcome,
 * which is the same shape EP-22 would have returned had the provider answered first
 * time.
 */
final class CompleteConfirmation implements ShouldQueue
{
    use Queueable;

    /** A slow provider is worth a few attempts; a broken one is not worth many. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly string $aiJobId) {}

    public function handle(ConfirmationService $confirmation): void
    {
        $job = AiJob::find($this->aiJobId);

        if ($job === null || $job->status === AiJob::STATUS_COMPLETED) {
            return;
        }

        $session = AttachSession::find((string) ($job->payload['session_id'] ?? ''));
        $store = Store::find((int) ($job->payload['store_id'] ?? 0));

        if ($session === null || $store === null) {
            /*
             * The session was consumed or the store went away. Retrying will not bring
             * either back, and a session that is gone most likely means the submission
             * already succeeded on another attempt.
             */
            $job->markFailed('That submission is no longer available to complete.');

            return;
        }

        $job->forceFill(['status' => AiJob::STATUS_PROCESSING])->save();

        try {
            $outcome = $confirmation->submit(
                $store,
                $session,
                (array) ($job->payload['answers'] ?? []),
                (array) ($job->payload['variant_ids'] ?? []),
                (int) ($job->payload['price_minor'] ?? 0),
                (string) ($job->payload['currency'] ?? 'LKR'),
            );
        } catch (AiUnavailable $e) {
            // Rethrown so the queue retries with backoff. failed() records the outcome
            // once the attempts are exhausted.
            $job->forceFill(['status' => AiJob::STATUS_QUEUED])->save();

            throw $e;
        } catch (ApiException $e) {
            /*
             * A domain refusal is final. The seller attached elsewhere in the meantime,
             * or a proposal of theirs opened, and no number of retries changes that.
             * Recording the code lets the client show the right screen rather than a
             * generic failure.
             */
            $job->markFailed($e->getMessage());

            return;
        }

        $job->markCompleted($this->resultFrom($outcome));
    }

    /**
     * The section 11.4 outcome, exactly as EP-22 would have returned it.
     *
     * @return array<string, mixed>
     */
    private function resultFrom(ConfirmationOutcome $outcome): array
    {
        if ($outcome->outcome === ConfirmationOutcome::ATTACHED) {
            return [
                'outcome' => ConfirmationOutcome::ATTACHED,
                'attachment_ids' => $outcome->attachments->pluck('id')->values()->all(),
            ];
        }

        return [
            'outcome' => ConfirmationOutcome::PROPOSAL_CREATED,
            'proposal_id' => $outcome->proposal?->id,
            'review_closes_at' => $outcome->proposal?->review_closes_at?->toIso8601String(),
        ];
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
