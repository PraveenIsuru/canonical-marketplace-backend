<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubmitVerificationRequest;
use App\Jobs\CompleteVerification;
use App\Models\AiJob;
use App\Models\Product;
use App\Services\Community\VerificationQueued;
use App\Services\Community\VerificationService;
use App\Services\Media\ImageUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proving ownership of a product (EP-33, EP-34, EP-35).
 *
 * The buyer is issued a code, writes it on paper, photographs it beside the product,
 * and submits that. The code is what makes the photograph evidence of present
 * possession rather than an image found online.
 *
 * **No method here returns a photograph path, and none can.** The service does not hand
 * one back, the model has no column for one, and the only place a path exists outside a
 * single request is inside a queued job payload. Section 6 of the contract lists this
 * alongside the confidence score.
 *
 * Thin. The ceiling, the scoping, and the deletion all live in VerificationService.
 */
final class VerificationController extends Controller
{
    public function __construct(private readonly VerificationService $verification) {}

    /**
     * EP-33 The state the composer renders from.
     *
     * Carries enough to answer every case the interface has to distinguish: signed in
     * but unverified, verified, out of attempts, mid attempt with a code already
     * issued, and waiting on a queued provider call. The client infers none of it.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $status = $this->verification->status($request->user(), $product);

        return response()->json([
            'data' => $status + [
                /*
                 * An outstanding queued judgement, so the queued job panel can resume
                 * rather than the buyer being invited to submit a second photograph for
                 * work already in flight.
                 */
                'pending_job_id' => $this->pendingJobId($request, $product),
            ],
        ]);
    }

    /**
     * EP-34 Issue a code and open an attempt.
     *
     * Starting consumes no attempt. Only a concluded submission counts against the
     * ceiling of five, so a buyer who starts and cannot photograph the product today
     * has lost nothing.
     */
    public function start(Request $request, Product $product): JsonResponse
    {
        $attempt = $this->verification->start($request->user(), $product);
        $status = $this->verification->status($request->user(), $product);

        return response()->json([
            'data' => [
                'code' => $attempt->generated_code,
                'attempts_remaining' => $status['attempts_remaining'],
                /*
                 * The code does not expire on a timer. The field is here because the
                 * contract carries it and a client may show a deadline later; today it
                 * reports the attempt's own age rather than a limit nothing enforces.
                 */
                'expires_at' => null,
            ],
        ]);
    }

    /**
     * EP-35 Submit the photograph.
     *
     * A failure answers **200, not a 4xx**. The request succeeded and the answer was no,
     * and a buyer who photographed the wrong thing has not made a bad request.
     *
     * Provider failure follows section 8: 503 with `ai_unavailable` and a top level
     * `queued_job_id`. The photograph survives only until the queued job concludes.
     */
    public function submit(SubmitVerificationRequest $request, Product $product): JsonResponse
    {
        $photo = $request->file('photo');

        // The shared rules, so a verification photograph is refused with the same
        // registered codes as a product image rather than a generic validation failure.
        ImageUpload::assertAcceptable($photo);

        try {
            $attempt = $this->verification->submit($request->user(), $product, $photo);
        } catch (VerificationQueued $queued) {
            throw $this->queue($request, $product, $queued);
        }

        $status = $this->verification->status($request->user(), $product);

        return response()->json([
            'data' => [
                'outcome' => $attempt->outcome,
                // Survives the photograph, so a failure can still be explained to
                // somebody deciding whether to spend another of their five attempts.
                'reason' => $attempt->ai_reasoning,
                'attempts_remaining' => $status['attempts_remaining'],
            ],
        ]);
    }

    /**
     * Queues the judgement and returns the 503 the contract defines.
     *
     * The photograph's location goes into the job payload here, and this is the only
     * place it travels anywhere. It is not written to the attempt row, because no
     * column holds a path, which is what makes it impossible for a serialiser to leak
     * one by accident.
     */
    private function queue(Request $request, Product $product, VerificationQueued $queued): ApiException
    {
        $job = AiJob::create([
            'user_id' => $request->user()->id,
            'type' => AiJob::TYPE_VERIFICATION_RESULT,
            'status' => AiJob::STATUS_QUEUED,
            'payload' => [
                'product_id' => $product->id,
                'attempt_id' => $queued->attemptId,
            ],
        ]);

        CompleteVerification::dispatch($job->id, $queued->attemptId, $queued->photoPath);

        return ApiException::aiUnavailable($job->id);
    }

    /** An outstanding verification job for this user on this product, if there is one. */
    private function pendingJobId(Request $request, Product $product): ?string
    {
        return AiJob::query()
            ->where('user_id', $request->user()->id)
            ->where('type', AiJob::TYPE_VERIFICATION_RESULT)
            ->whereIn('status', [AiJob::STATUS_QUEUED, AiJob::STATUS_PROCESSING])
            ->where('payload->product_id', $product->id)
            ->latest('id')
            ->value('id');
    }
}
