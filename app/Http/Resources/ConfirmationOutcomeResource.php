<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Services\Attach\ConfirmationOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EP-22, following section 11.4 of the contract exactly.
 *
 * One endpoint, two outcomes, told apart by the `outcome` field and **not** by the
 * status code. Both are successful submissions and both answer 201: a proposal is not
 * a failure to attach, it is the platform doing the thing it exists to do.
 *
 * The two carry different keys on purpose, so a client that forgets to branch on
 * `outcome` fails loudly rather than rendering an attached state for a blocked seller.
 *
 * Nothing here carries a confidence score. It is written to the proposal and read at
 * resolution, and it appears in no response body on any endpoint at any access level.
 */
final class ConfirmationOutcomeResource extends JsonResource
{
    public function __construct(private readonly ConfirmationOutcome $result)
    {
        parent::__construct($result);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->result->outcome === ConfirmationOutcome::ATTACHED) {
            return [
                'outcome' => ConfirmationOutcome::ATTACHED,
                'attachment_ids' => $this->result->attachments
                    ->map(static fn (Attachment $attachment): int => $attachment->id)
                    ->values()
                    ->all(),
            ];
        }

        $proposal = $this->result->proposal;

        return [
            'outcome' => ConfirmationOutcome::PROPOSAL_CREATED,
            'proposal_id' => $proposal?->id,
            /*
             * The deadline, and the only date the client needs. It is exactly three
             * days after the proposal opened, fixed platform wide, and it is what the
             * blocked state counts down to.
             */
            'review_closes_at' => $proposal?->review_closes_at?->toIso8601String(),
        ];
    }
}
