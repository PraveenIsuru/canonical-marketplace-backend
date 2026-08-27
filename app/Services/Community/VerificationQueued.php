<?php

declare(strict_types=1);

namespace App\Services\Community;

use RuntimeException;

/**
 * The provider could not judge a photograph, so the work has to be queued.
 *
 * Exists to carry two things out of the service that a plain AiUnavailable cannot: the
 * attempt that is waiting, and where the photograph is until the job runs.
 *
 * **The path travels in a queued job payload and nowhere else.** It is never written to
 * a column, never returned, and never rendered. The M0 migration says so in as many
 * words, and this class is the mechanism that keeps it true: the path exists in memory
 * between the service and the dispatch, and then only inside the job.
 */
final class VerificationQueued extends RuntimeException
{
    public function __construct(
        public readonly int $attemptId,
        public readonly string $photoPath,
    ) {
        parent::__construct('Verification could not be judged and has been queued.');
    }
}
