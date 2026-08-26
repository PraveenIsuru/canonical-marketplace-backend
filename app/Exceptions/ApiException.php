<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A domain error with a registered code from the API contract.
 *
 * Throw this rather than aborting with a bare status, so the code that reaches the
 * client is deliberate. Every code used here must exist in section 7 of
 * development-docs/shared/api-contract.md. Add the row there before using a new one.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly ?array $errors = null,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * The registered error code.
     *
     * Not named getCode(), because Exception::getCode() is final in PHP and returns
     * an int. Our codes are the fixed strings clients branch on.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, array<int, string>>|null */
    public function errors(): ?array
    {
        return $this->errors;
    }

    // Named constructors for the codes the platform actually uses. Each one keeps the
    // status and the code paired correctly at the single place it is defined.

    public static function storeRequired(): self
    {
        return new self(403, 'store_required', 'You need a store to do that.');
    }

    public static function storeExists(): self
    {
        return new self(409, 'store_exists', 'You already have a store. One user holds one store.');
    }

    public static function forbidden(string $message = 'You are not permitted to do that.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'The requested resource does not exist.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function proposalPending(): self
    {
        return new self(
            409,
            'proposal_pending',
            'You have a proposal awaiting review for this product. It must resolve first.',
        );
    }

    public static function alreadyAttached(): self
    {
        return new self(409, 'already_attached', 'Your store already carries this product.');
    }

    public static function confirmationIncomplete(): self
    {
        return new self(422, 'confirmation_incomplete', 'Every question must be answered.');
    }

    public static function matchRequired(): self
    {
        return new self(422, 'match_required', 'Choose one of the matched products first.');
    }

    public static function alreadyVoted(): self
    {
        return new self(409, 'already_voted', 'Your store has already voted on this proposal.');
    }

    public static function reviewClosed(): self
    {
        return new self(409, 'review_closed', 'The review window for this proposal has closed.');
    }

    public static function notEligibleToVote(): self
    {
        return new self(
            403,
            'not_eligible_to_vote',
            'Only stores attached when the proposal opened may vote on it.',
        );
    }

    public static function notAttached(): self
    {
        return new self(
            403,
            'not_attached',
            'Version history is visible to sellers carrying this product and to administrators.',
        );
    }

    public static function notVerified(): self
    {
        return new self(403, 'not_verified', 'Posting requires verified ownership of this product.');
    }

    public static function attemptsExhausted(): self
    {
        return new self(
            403,
            'attempts_exhausted',
            'No verification attempts remain for this product. The limit applies to this product only.',
        );
    }

    public static function unsupportedMediaType(): self
    {
        return new self(422, 'unsupported_media_type', 'Images must be JPEG, PNG, or WebP.');
    }

    public static function fileTooLarge(): self
    {
        return new self(422, 'file_too_large', 'Images must be 5 MB or smaller.');
    }

    public static function imageLimitReached(): self
    {
        return new self(422, 'image_limit_reached', 'A product holds at most eight images.');
    }

    /**
     * AI provider unavailability.
     *
     * The queued job id goes at the top level of the body rather than inside data, so
     * the client can poll it. Buyer search never throws this; it falls back to keyword
     * results instead.
     */
    public static function aiUnavailable(string $queuedJobId): AiUnavailableException
    {
        return new AiUnavailableException($queuedJobId);
    }
}
