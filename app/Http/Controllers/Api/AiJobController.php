<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiJobResource;
use App\Models\AiJob;
use Illuminate\Http\Request;

/**
 * Polling a queued AI job (EP-50).
 *
 * This endpoint exists for one reason: recovering a flow that blocked because the AI
 * provider was unavailable. Every AI path in the platform except buyer search queues
 * its work and hands back a job id, and this is where that id is redeemed.
 */
final class AiJobController extends Controller
{
    /**
     * EP-50 The status and result of one job.
     *
     * A job is readable only by the user who created it. The id is a bearer of nothing
     * on its own, and without this check it would be a way to read another seller's
     * match candidates, which are commercially interesting: they say what a competitor
     * is about to start selling.
     *
     * A job that does not exist and a job belonging to someone else both answer 404.
     * Distinguishing them would confirm that an id is real, which is the one fact an
     * enumeration attempt is trying to establish.
     */
    public function show(Request $request, string $job): AiJobResource
    {
        $aiJob = AiJob::find($job);

        if ($aiJob === null || $aiJob->user_id !== $request->user()?->id) {
            throw ApiException::notFound('That job does not exist.');
        }

        return new AiJobResource($aiJob);
    }
}
