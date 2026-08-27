<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AiUnavailableException;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MatchProductRequest;
use App\Http\Requests\Api\StartWizardRequest;
use App\Http\Requests\Api\SubmitWizardRequest;
use App\Http\Resources\MatchCandidateResource;
use App\Jobs\GenerateWizardQuestions;
use App\Jobs\MatchProduct;
use App\Models\AiJob;
use App\Models\AttachSession;
use App\Models\Store;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\ProductDraft;
use App\Services\Attach\ProductMatchingService;
use App\Services\Attach\ProductWizardService;
use App\Services\Media\ImageUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The attachment flow: matching and the listing wizard (EP-20, EP-23, EP-24).
 *
 * Every path through here answers one question. Is the product a seller is describing
 * already in the catalogue? If it is, they go to confirmation and peer review, which
 * lands at M6. If it is not, they build the record through the wizard, and no review
 * happens because there is nobody attached to review it.
 *
 * Every AI call in this controller blocks and queues on provider failure. None of them
 * degrades. Buyer search is the platform's only exception to that rule, and duplicate
 * detection is the last place it would be safe to make a second one.
 */
final class AttachController extends Controller
{
    public function __construct(
        private readonly ProductMatchingService $matching,
        private readonly ProductWizardService $wizard,
    ) {}

    /**
     * EP-20 Match a described product against the catalogue.
     *
     * An empty candidate list is a successful answer, not an error. It means the
     * catalogue holds nothing like this and the seller should go to the wizard.
     *
     * Where candidates come back the seller must choose one. There is no field on any
     * request that lets them overrule the result and declare their product new, and
     * there is no endpoint that would accept it if there were.
     */
    public function match(MatchProductRequest $request): JsonResponse
    {
        $draft = $this->draftFrom($request);

        try {
            $candidates = $this->matching->candidates($draft);
        } catch (AiUnavailable) {
            throw $this->queueMatch($request, $draft);
        }

        // Loaded after scoring rather than during it, so images are fetched only for
        // the handful of products that actually came back.
        $candidates->load('images');

        return response()->json([
            'data' => ['candidates' => MatchCandidateResource::collection($candidates)->resolve()],
        ]);
    }

    /**
     * EP-23 Open the listing wizard.
     *
     * Matching is re-run here rather than trusting the client to have found nothing.
     * The rule that the wizard is reachable only when matching returned nothing is the
     * platform's whole defence against duplicate canonical records, and a check the
     * client performs on itself is not a defence at all. It costs one provider call to
     * make `match_required` mean something.
     */
    public function wizardStart(StartWizardRequest $request): JsonResponse
    {
        $store = $this->callerStore($request);
        $draft = $this->draftFrom($request);

        try {
            $candidates = $this->matching->candidates($draft);

            if ($candidates->isNotEmpty()) {
                throw ApiException::matchRequired();
            }

            $session = $this->wizard->startSession($store, $draft);
        } catch (AiUnavailable) {
            throw $this->queueWizard($request, $store, $draft);
        }

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'questions' => $session->questions,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * EP-24 Submit the wizard and create the canonical record.
     *
     * One transaction over six tables, and the only endpoint in the platform that
     * writes a product directly. Everything it creates is permanent: there is no
     * product deletion path, and a generated variant combination can never be removed.
     */
    public function wizardSubmit(SubmitWizardRequest $request): JsonResponse
    {
        $store = $this->callerStore($request);
        $session = $this->wizardSession($store, (string) $request->validated('session_id'));

        $this->assertEveryQuestionAnswered($session, (array) $request->validated('answers'));

        $result = $this->wizard->submit($store, $session, $request->validated());

        return response()->json([
            'data' => [
                'product' => [
                    'id' => $result->product->id,
                    'slug' => $result->product->slug,
                    'current_version_number' => $result->versionNumber,
                ],
                /*
                 * Reported separately, and the first will usually be larger. Every
                 * combination the attributes can produce was created, while attachments
                 * exist only for the ones this seller carries. The gap is expected and
                 * is not an inconsistency.
                 */
                'variants_generated' => $result->variantsGenerated,
                'attachments_created' => $result->attachmentsCreated,
                'store_is_live' => $result->storeIsLive,
            ],
        ], 201);
    }

    /**
     * The seller's own wizard session.
     *
     * Ownership is checked here rather than by scoping the lookup, so a session
     * belonging to another store is refused as forbidden rather than reported as
     * missing. Both are safe, but a seller who pasted the wrong id is better served by
     * being told it is not theirs.
     */
    private function wizardSession(Store $store, string $sessionId): AttachSession
    {
        $session = AttachSession::find($sessionId);

        if ($session === null || $session->type !== AttachSession::TYPE_WIZARD) {
            throw ApiException::notFound('That wizard session does not exist.');
        }

        if ($session->store_id !== $store->id) {
            throw ApiException::forbidden('That wizard session belongs to another store.');
        }

        if ($session->hasExpired()) {
            /*
             * Expiry is refused as match_required rather than as a plain 422, because
             * the seller has to go back to the beginning: the catalogue may have gained
             * the very product they are describing while the session sat open, and
             * matching has to run again before the wizard can be opened a second time.
             */
            throw ApiException::matchRequired();
        }

        return $session;
    }

    /**
     * Every question the session asked must carry a non empty answer.
     *
     * Checked against the stored questions, never against what the client sent. A
     * client supplying both the questions and the answers could always report itself
     * complete, which would make the whole check theatre.
     *
     * @param  array<string, mixed>  $answers
     */
    private function assertEveryQuestionAnswered(AttachSession $session, array $answers): void
    {
        $missing = [];

        foreach ($session->questionIds() as $id) {
            $answer = $answers[$id] ?? null;

            if (! is_string($answer) || trim($answer) === '') {
                $missing["answers.{$id}"] = ['This question must be answered.'];
            }
        }

        if ($missing !== []) {
            /*
             * Reported as validation_failed rather than confirmation_incomplete.
             *
             * That code belongs to the confirmation flow at M6, where an unanswered
             * question means a seller skipped part of a review of an existing record.
             * Here the errors name the specific questions, which is more useful, and
             * borrowing the other code would make a client handling it show the wrong
             * screen.
             */
            throw new ApiException(422, 'validation_failed', 'Every question must be answered.', $missing);
        }
    }

    /**
     * The product details, plus the transient image where one was uploaded.
     *
     * A matching image is never stored as a product image. It is a photograph of the
     * thing on the seller's shelf, used to answer one question and then discarded, and
     * treating it as catalogue content would put an unreviewed snapshot on a canonical
     * record.
     */
    private function draftFrom(Request $request): ProductDraft
    {
        $image = $request->file('image');
        $path = null;

        if ($image !== null) {
            ImageUpload::assertAcceptable($image);

            // The upload's own temporary path. PHP removes it when the request ends,
            // which is exactly the lifetime this image should have.
            $path = $image->getRealPath() ?: null;
        }

        return new ProductDraft(
            name: (string) $request->input('name'),
            description: $request->input('description') !== null ? (string) $request->input('description') : null,
            category: $request->input('category') !== null ? (string) $request->input('category') : null,
            imagePath: $path,
        );
    }

    /**
     * Queues a matching call the provider could not answer.
     *
     * The image is deliberately not carried into the payload. It lives only for the
     * length of the request, so a job running minutes later could not read it, and
     * persisting it would mean storing a photograph the seller was told was transient.
     */
    private function queueMatch(Request $request, ProductDraft $draft): AiUnavailableException
    {
        $job = AiJob::create([
            'user_id' => $request->user()?->id,
            'type' => AiJob::TYPE_MATCH_CANDIDATES,
            'status' => AiJob::STATUS_QUEUED,
            'payload' => $draft->toArray(),
        ]);

        MatchProduct::dispatch($job->id);

        return new AiUnavailableException($job->id);
    }

    /**
     * Queues wizard question generation the provider could not answer.
     *
     * The store id travels in the payload because the job opens the session itself.
     * The seller may have closed the browser by the time the provider recovers, and a
     * session that only came into being if someone was watching would lose the flow at
     * precisely the moment this recovery path exists to save it.
     */
    private function queueWizard(Request $request, Store $store, ProductDraft $draft): AiUnavailableException
    {
        $job = AiJob::create([
            'user_id' => $request->user()?->id,
            'type' => AiJob::TYPE_WIZARD_QUESTIONS,
            'status' => AiJob::STATUS_QUEUED,
            'payload' => [...$draft->toArray(), 'store_id' => $store->id],
        ]);

        GenerateWizardQuestions::dispatch($job->id);

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
