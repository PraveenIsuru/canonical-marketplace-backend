<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An administrator's decision on a proposal (EP-41, EP-42), per section 11.12.
 *
 * One field, and nothing else is accepted. **A proposal is taken or left as a whole**,
 * per invariant 4, so there is no per field decision here and no shape that would
 * invite one. Adding an "apply these changes only" field would be a hole in the rule
 * that peer review exists to enforce.
 *
 * There is no free text reason either. `resolution_reason` is a coded audit vocabulary
 * recording why the matrix escalated a proposal, and overwriting it with prose would
 * trade a fact the system can query for one it cannot.
 */
final class AdminDecisionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
        ];
    }

    /** @return array<string, mixed> */
    public function messages(): array
    {
        return [
            'decision.in' => 'A decision is either approve or reject.',
        ];
    }

    public function isApproval(): bool
    {
        return $this->validated('decision') === 'approve';
    }
}
