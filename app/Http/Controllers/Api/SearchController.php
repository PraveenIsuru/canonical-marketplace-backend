<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AiUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductSummaryResource;
use App\Jobs\InterpretSearchQuery;
use App\Models\AiJob;
use App\Services\Ai\AiUnavailable;
use App\Services\Search\ProductSearchService;
use App\Services\Search\SearchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Search (EP-14 and EP-15).
 *
 * Two endpoints over the same query, with deliberately opposite failure behaviour.
 * That difference is the whole point of this controller, and it is the one thing here
 * that must not be "tidied up" into shared handling.
 */
final class SearchController extends Controller
{
    public function __construct(private readonly ProductSearchService $search) {}

    /**
     * EP-14 Buyer search. Public.
     *
     * **Never returns ai_unavailable and never queues work.** On any provider failure
     * or timeout it falls back to keyword results and still answers 200, because search
     * is the availability floor for buyer discovery: a buyer who cannot search cannot
     * find anything at all, and an error page serves them far worse than an imperfect
     * result list does.
     *
     * `mode` tells the client which path served the query, so the fallback is visible
     * rather than silent.
     */
    public function buyer(Request $request): JsonResponse
    {
        [$query, $category] = $this->validated($request);

        try {
            $result = $this->search->interpreted($query, $category);
        } catch (AiUnavailable) {
            // Deliberately swallowed. This is the single endpoint in the platform where
            // provider failure is not surfaced to the caller as a failure.
            $result = $this->search->keyword($query, $category);
        }

        return $this->respond($result);
    }

    /**
     * EP-15 Seller catalogue search. Seller only.
     *
     * **Not an exception to the AI unavailability rule.** On provider failure it queues
     * the work and returns 503 with a queued job id, exactly like every other AI path.
     *
     * It cannot fall back to keyword results the way buyer search does. This endpoint
     * feeds duplicate detection, and a degraded result could let a seller past the
     * check and create a second canonical record for a product that already exists,
     * which is the outcome the whole platform is built to prevent.
     */
    public function sellerCatalogue(Request $request): JsonResponse
    {
        [$query, $category] = $this->validated($request);

        try {
            return $this->respond($this->search->interpreted($query, $category));
        } catch (AiUnavailable $e) {
            $job = AiJob::create([
                'user_id' => $request->user()?->id,
                'type' => AiJob::TYPE_SEARCH_INTERPRETATION,
                'status' => AiJob::STATUS_QUEUED,
                'payload' => ['query' => $query, 'category' => $category],
            ]);

            InterpretSearchQuery::dispatch($job->id);

            // The renderer puts queued_job_id at the top level of the body, outside
            // data, which is where the client looks for it.
            throw new AiUnavailableException($job->id);
        }
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        return [$validated['q'], $validated['category'] ?? null];
    }

    /**
     * Builds the search envelope.
     *
     * `mode` sits beside `data` at the top level, never inside it. The client reads it
     * to decide whether to show the degraded search notice, so its position is part of
     * the contract rather than a formatting choice.
     */
    private function respond(SearchResult $result): JsonResponse
    {
        $payload = ProductSummaryResource::collection($result->results)
            ->response()
            ->getData(true);

        return response()->json([
            'mode' => $result->mode->value,
            ...$payload,
        ]);
    }
}
