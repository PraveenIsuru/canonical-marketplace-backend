<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * EP-30 A reviewer's vote on a proposal, per section 11.8.
 *
 * Two values only. There is deliberately no third for abstaining: a reviewer who has
 * no view simply does not vote, and the absence of a row is what makes them a non
 * voter and excludes them from the denominator. Storing abstention would turn silence
 * into a position and change every majority calculation.
 *
 * There is also no field level vote. A proposal is accepted or rejected as a whole,
 * and accepting a request shaped for anything else would be the first step to a
 * control invariant 4 forbids.
 */
final class VoteOnProposalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vote' => ['required', 'string', Rule::in(['approve', 'reject'])],
            // Free text, and optional. A reviewer explaining why they voted against is
            // useful to an administrator when the proposal escalates.
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** True when the vote is in favour, which is what the resolution matrix counts. */
    public function isInFavour(): bool
    {
        return $this->validated('vote') === 'approve';
    }

    public function comment(): ?string
    {
        $comment = $this->validated('comment');

        return is_string($comment) && trim($comment) !== '' ? trim($comment) : null;
    }
}
