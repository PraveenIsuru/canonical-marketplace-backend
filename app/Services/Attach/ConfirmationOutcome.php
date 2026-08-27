<?php

declare(strict_types=1);

namespace App\Services\Attach;

use App\Models\Attachment;
use App\Models\Proposal;
use Illuminate\Support\Collection;

/**
 * What a confirmation submission produced.
 *
 * One endpoint, two outcomes, and the client tells them apart by this field rather
 * than by a status code. Both are successful submissions, so both answer 201, and a
 * proposal is not a failure to attach: it is the platform working.
 *
 * The two are mutually exclusive by design. **No attachment is created alongside a
 * proposal**, because the absence of an attachment row *is* the block on the proposing
 * seller. Anything that populated both would quietly let a seller sell a product whose
 * description is still being argued about.
 */
final readonly class ConfirmationOutcome
{
    public const ATTACHED = 'attached';

    public const PROPOSAL_CREATED = 'proposal_created';

    /**
     * @param  Collection<int, Attachment>  $attachments
     */
    private function __construct(
        public string $outcome,
        public Collection $attachments,
        public ?Proposal $proposal,
    ) {}

    /** @param  Collection<int, Attachment>  $attachments */
    public static function attached(Collection $attachments): self
    {
        return new self(self::ATTACHED, $attachments, null);
    }

    public static function proposalCreated(Proposal $proposal): self
    {
        return new self(self::PROPOSAL_CREATED, new Collection, $proposal);
    }
}
