<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A queued AI job, as the client polls it (EP-50).
 *
 * This endpoint exists for one purpose: recovering a flow that blocked because the
 * provider was unavailable. The client persists the job id, polls on a widening
 * backoff, and resumes from the result.
 *
 * `result_type` is what tells the client which flow it is resuming, so it is always
 * present, and it is null only until there is a result to describe.
 *
 * @mixin AiJob
 */
final class AiJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isFinished = $this->status === AiJob::STATUS_COMPLETED;

        return [
            'id' => $this->id,
            'status' => $this->status,
            /*
             * Null while the work is still outstanding.
             *
             * Naming the type before there is a result would let a client start
             * resuming a flow it has no answer for yet, and a failed job never gets a
             * result at all, so the type would be describing something that does not
             * exist.
             */
            'result_type' => $isFinished ? $this->type : null,
            'result' => $isFinished ? $this->result : null,
        ];
    }
}
