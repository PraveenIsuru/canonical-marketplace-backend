<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AiProvider;
use App\Models\AiJob;
use App\Models\VerificationAttempt;
use App\Services\Ai\AiUnavailable;
use App\Services\Community\VerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Judges a verification photograph the provider could not judge in time.
 *
 * **The photograph is the reason this job carries a payload at all.** Everything else
 * about an attempt is on its row; the photograph is not, because no column holds a
 * path. It lives on the private disk between the request and this job, and its
 * location travels here in the job payload and nowhere else.
 *
 * The obligation this job inherits is the one that matters most in the milestone: the
 * photograph is deleted whichever way the judgement goes, and also when the judgement
 * never happens. `failed()` below is not a courtesy, it is the same guarantee holding
 * when the provider stays down.
 */
final class CompleteVerification implements ShouldQueue
{
    use Queueable;

    /** A slow provider is worth a few attempts; a broken one is not worth many. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly string $aiJobId,
        public readonly int $attemptId,
        public readonly string $photoPath,
    ) {}

    public function handle(AiProvider $ai, VerificationService $verification): void
    {
        $job = AiJob::find($this->aiJobId);

        if ($job === null || $job->status === AiJob::STATUS_COMPLETED) {
            return;
        }

        $attempt = VerificationAttempt::with('product')->find($this->attemptId);

        if ($attempt === null || $attempt->isConcluded()) {
            /*
             * Already judged, or gone. Retrying cannot change either, but the photograph
             * may still be sitting there, so it goes before this returns.
             */
            $verification->deletePhoto($this->photoPath);
            $job->markFailed('That verification is no longer available to complete.');

            return;
        }

        $job->forceFill(['status' => AiJob::STATUS_PROCESSING])->save();

        $disk = (string) config('filesystems.verification_photos', 'verification_photos');
        $photo = Storage::disk($disk)->exists($this->photoPath)
            ? (string) Storage::disk($disk)->get($this->photoPath)
            : null;

        if ($photo === null) {
            // Swept, or already deleted by an earlier run of this job that got further
            // than it recorded. There is nothing left to judge.
            $job->markFailed('The photograph is no longer available.');

            return;
        }

        try {
            $assessment = $ai->verifyOwnership(
                $attempt->product,
                $attempt->generated_code,
                $photo,
                (string) (Storage::disk($disk)->mimeType($this->photoPath) ?: 'image/jpeg'),
            );
        } catch (AiUnavailable $e) {
            // Rethrown so the queue retries with backoff. The photograph stays until
            // either a retry judges it or failed() gives up and deletes it.
            $job->forceFill(['status' => AiJob::STATUS_QUEUED])->save();

            throw $e;
        }

        // Concludes and deletes the photograph, through the same method the synchronous
        // path uses. One place decides an outcome, one place destroys a photograph.
        $verification->conclude($attempt, $assessment, $this->photoPath);

        $job->markCompleted([
            'outcome' => $attempt->refresh()->outcome,
            'reason' => $attempt->ai_reasoning,
        ]);
    }

    /**
     * The provider never recovered.
     *
     * **The photograph goes anyway.** It was collected for one purpose, that purpose
     * cannot now be served, and keeping it because the judgement failed would be the
     * one way a verification photograph outlives the verification. The attempt is left
     * pending rather than failed, so the buyer has not spent one of their five on the
     * platform's outage.
     */
    public function failed(?Throwable $exception): void
    {
        app(VerificationService::class)->deletePhoto($this->photoPath);

        AiJob::find($this->aiJobId)?->markFailed(
            'The photograph could not be checked. Your attempt has not been used.',
        );
    }
}
