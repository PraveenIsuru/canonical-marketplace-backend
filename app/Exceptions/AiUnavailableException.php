<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The AI provider failed and the work has been queued.
 *
 * Carries the queued job id at the top level of the response body, outside `data`,
 * so the client can poll it and resume the flow rather than losing the submission.
 *
 * Every AI dependent endpoint throws this on provider failure. The single exception
 * is buyer search, which falls back to keyword results and returns 200 instead,
 * because search is the availability floor for buyer discovery.
 */
final class AiUnavailableException extends ApiException
{
    public function __construct(private readonly string $queuedJobId)
    {
        parent::__construct(
            503,
            'ai_unavailable',
            'This is taking longer than usual. Your submission has been saved and will finish shortly.',
        );
    }

    public function queuedJobId(): string
    {
        return $this->queuedJobId;
    }
}
