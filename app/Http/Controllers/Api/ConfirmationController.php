<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StartConfirmationRequest;
use App\Http\Requests\Api\SubmitConfirmationRequest;
use App\Http\Resources\ConfirmationOutcomeResource;
use App\Jobs\CompleteConfirmation;
use App\Models\AiJob;
use App\Models\AttachSession;
use App\Models\Product;
use App\Models\Store;
use App\Services\Ai\AiUnavailable;
use App\Services\Attach\ConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The confirmation flow (EP-21, EP-22).
 *
 * A seller joining a record the catalogue already holds. They cannot edit it, so the
 * only way their knowledge reaches the record is by answering questions about the
 * product and having any differences reviewed by the other sellers who carry it.
 *
 * Thin by design. Every decision lives in ConfirmationService; these methods translate
 * between HTTP and it, and decide only how to fail.
 */
final class ConfirmationController extends Controller
{
    public function __construct(private readonly ConfirmationService $confirmation) {}

    /**
     * EP-21 Open confirmation and return the questions.
     *
     * Every attribute on the record is questioned, every time, without exception. An
     * attribute treated as settled is one that can never be corrected, and a seller who
     * looks like they are attaching may in fact be describing a variant the record does
     * not hold.
     */
    public function start(StartConfirmationRequest $request): JsonResponse
    {
        $store = $this->callerStore($request);
        $product = Product::find((int) $request->validated('product_id'));

        if ($product === null) {
            throw ApiException::notFound('That product does not exist.');
        }

        try {
            $session = $this->confirmation->start($store, $product);
        } catch (AiUnavailable) {
            throw $this->queueQuestions($request, $store, $product);
        }

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'product_id' => $product->id,
                /*
                 * The questions only. `current_value` is stored on the session but
                 * never sent: showing the seller the answer we expect would turn
                 * confirmation into a yes or no exercise, and the value of the flow is
                 * that they say what their unit is without being led to ours.
                 */
                'questions' => array_map(
                    static fn (array $question): array => [
                        'id' => $question['id'],
                        'attribute' => $question['attribute'],
                        'text' => $question['text'],
                    ],
                    $session->questions,
                ),
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * EP-22 Submit the answers.
     *
     * Two outcomes, told apart by the `outcome` field rather than by the status code,
     * per section 11.4 of the contract. Both answer 201, because both are successful
     * submissions: a proposal is not a failure to attach.
     */
    public function submit(SubmitConfirmationRequest $request): JsonResponse
    {
        $store = $this->callerStore($request);
        $session = $this->confirmationSession($store, (string) $request->validated('session_id'));

        /** @var array<string, string> $answers */
        $answers = (array) $request->validated('answers');
        /** @var array<int, int> $variantIds */
        $variantIds = (array) $request->validated('variant_ids');
        $priceMinor = (int) $request->validated('price_minor');
        $currency = (string) ($request->validated('currency') ?? 'LKR');

        try {
            $outcome = $this->confirmation->submit($store, $session, $answers, $variantIds, $priceMinor, $currency);
        } catch (AiUnavailable) {
            throw $this->queueSubmission($request, $store, $session, $answers, $variantIds, $priceMinor, $currency);
        }

        return (new ConfirmationOutcomeResource($outcome))->response()->setStatusCode(201);
    }

    /**
     * The seller's own confirmation session.
     *
     * A session belonging to another store is refused as forbidden rather than reported
     * as missing. Both are safe, but a seller who pasted the wrong id is better served
     * by being told it is not theirs.
     */
    private function confirmationSession(Store $store, string $sessionId): AttachSession
    {
        $session = AttachSession::with('product')->find($sessionId);

        if ($session === null || $session->type !== AttachSession::TYPE_CONFIRMATION) {
            throw ApiException::notFound('That confirmation session does not exist.');
        }

        if ($session->store_id !== $store->id) {
            throw ApiException::forbidden('That confirmation session belongs to another store.');
        }

        if ($session->hasExpired()) {
            /*
             * Expiry is a plain refusal here, unlike the wizard, which sends the seller
             * back through matching. The product is known and still exists; only the
             * questions are stale, so the flow restarts at EP-21 rather than at EP-20.
             */
            throw new ApiException(
                422,
                'validation_failed',
                'That confirmation has expired. Start it again to get fresh questions.',
                ['session_id' => ['This confirmation session has expired.']],
            );
        }

        return $session;
    }

    /** Queues question generation the provider could not perform. */
    private function queueQuestions(Request $request, Store $store, Product $product): AiUnavailableException
    {
        $job = AiJob::create([
            'user_id' => $request->user()?->id,
            'type' => AiJob::TYPE_CONFIRMATION_QUESTIONS,
            'status' => AiJob::STATUS_QUEUED,
            'payload' => ['store_id' => $store->id, 'product_id' => $product->id],
        ]);

        // No job class yet: regenerating questions is cheap to re-request and the
        // seller has lost nothing but a moment. What must not be lost is a submission,
        // which is why only that path carries a worker.
        return new AiUnavailableException($job->id);
    }

    /**
     * Queues a submission the provider could not score.
     *
     * The answers travel with the job, so the work is genuinely saved rather than
     * merely promised. A second submit while one is outstanding returns **the same job
     * id**, which is what directs the seller to the submission already in flight
     * instead of creating a duplicate proposal.
     *
     * @param  array<string, string>  $answers
     * @param  array<int, int>  $variantIds
     */
    private function queueSubmission(
        Request $request,
        Store $store,
        AttachSession $session,
        array $answers,
        array $variantIds,
        int $priceMinor,
        string $currency,
    ): AiUnavailableException {
        if ($session->ai_job_id !== null) {
            return new AiUnavailableException($session->ai_job_id);
        }

        $job = AiJob::create([
            'user_id' => $request->user()?->id,
            'type' => AiJob::TYPE_CONFIRMATION_OUTCOME,
            'status' => AiJob::STATUS_QUEUED,
            'payload' => [
                'session_id' => $session->id,
                'store_id' => $store->id,
                'answers' => $answers,
                'variant_ids' => $variantIds,
                'price_minor' => $priceMinor,
                'currency' => $currency,
            ],
        ]);

        $session->forceFill(['ai_job_id' => $job->id])->save();

        CompleteConfirmation::dispatch($job->id);

        return new AiUnavailableException($job->id);
    }

    /**
     * The caller's store.
     *
     * The seller middleware has already established that one exists, so reaching the
     * refusal here would mean it vanished mid request. Guarding anyway keeps that a
     * clean 403 rather than a null dereference.
     */
    private function callerStore(Request $request): Store
    {
        return $request->user()->store ?? throw ApiException::storeRequired();
    }
}
