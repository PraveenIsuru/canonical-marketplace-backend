<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gemini's half of a provider call.
 *
 * The only class in the application that knows Gemini exists. Like the Anthropic
 * transport it carries no prompt: it translates a neutral request into what
 * generateContent expects and hands back whatever text came out.
 *
 * generateContent is Google's older interface and there is a newer one, but it is the
 * right fit here. Every call this platform makes is a single stateless question with a
 * JSON answer, and the newer interface's value is stored conversations, which it keeps
 * server side by default. Verification photographs are deleted the moment the check
 * concludes, so an endpoint that retains them by default would quietly break a promise
 * the platform makes. Moving later would be one more class in this directory.
 */
final class GeminiTransport implements AiTransport
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Room for the model's own reasoning on top of the answer we asked for.
     *
     * maxOutputTokens is a combined budget for thinking and output, not a cap on the
     * answer alone. The platform's requests are sized for their answers, some as small
     * as 256 tokens, and a model left to think inside that budget can spend all of it
     * and return finishReason MAX_TOKENS with no text at all, which reads in a log
     * exactly like an outage.
     *
     * Costs nothing when it goes unused: the budget is a ceiling, not a spend.
     */
    private const THINKING_HEADROOM_TOKENS = 2048;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        /**
         * Short on purpose. A slow provider must degrade to keyword search rather than
         * hang the request, and buyer search is the availability floor for discovery.
         */
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function ask(AiRequest $request): string
    {
        /** @var array<int, array<string, mixed>> $parts */
        $parts = [['text' => $request->prompt]];

        foreach ($request->images as $image) {
            $parts[] = ['inline_data' => [
                'mime_type' => $image->mimeType,
                'data' => $image->base64(),
            ]];
        }

        $response = ProviderCall::orFail(fn (): Response => Http::withHeaders([
            /*
             * The key travels in a header, never in the query string. Both are accepted,
             * but a URL reaches access logs, proxy logs and exception traces, and the
             * whole point of reading failure reasons out of the response body is that
             * nothing this class holds can be echoed into a log by accident.
             */
            'x-goog-api-key' => $this->apiKey,
        ])
            ->timeout($this->timeoutSeconds)
            ->post(self::ENDPOINT.$this->model.':generateContent', [
                'contents' => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'maxOutputTokens' => $request->maxTokens + self::THINKING_HEADROOM_TOKENS,
                    /*
                     * What lets the shared prompts work here unchanged. Asked in prose
                     * alone the model wraps its JSON in a markdown fence often enough to
                     * matter, and stripping fences would be guessing at a reply we did
                     * not get. It does not make the JSON guard upstream redundant: a
                     * truncated reply is still not valid JSON.
                     */
                    'responseMimeType' => 'application/json',
                ],
                /*
                 * No thinkingConfig. The configured model's own default is the right
                 * one to use, and forcing a level here would make GEMINI_MODEL a lie:
                 * the levels are not supported uniformly, and the newest Flash rejects
                 * outright the setting the Flash Lite models default to.
                 */
            ]));

        /*
         * A blocked prompt comes back HTTP 200 with no candidates at all, so nothing
         * upstream saw a failure. Named in the message because "no answer" and "we
         * refused to look at your photograph" call for different responses from whoever
         * reads the log, and the verification photographs are the likely trigger.
         */
        $blockReason = $response->json('promptFeedback.blockReason');

        if (is_string($blockReason)) {
            throw AiUnavailable::because("the provider blocked the request ({$blockReason})");
        }

        $text = $this->textFrom($response);

        if ($text !== null) {
            return $text;
        }

        $finishReason = $response->json('candidates.0.finishReason');

        if ($finishReason === 'MAX_TOKENS') {
            throw AiUnavailable::because('the reply was cut off before it was complete');
        }

        throw AiUnavailable::because(is_string($finishReason)
            ? "the provider returned no text content ({$finishReason})"
            : 'the provider returned no text content');
    }

    /**
     * The first part of the reply that carries an answer.
     *
     * Scanned rather than indexed. A reply can begin with a part holding the model's
     * reasoning rather than its answer, and taking the first part blindly would report a
     * perfectly good reply as an outage.
     */
    private function textFrom(Response $response): ?string
    {
        /** @var array<int, mixed> $parts */
        $parts = (array) $response->json('candidates.0.content.parts', []);

        foreach ($parts as $part) {
            if (! is_array($part) || ($part['thought'] ?? false) === true) {
                continue;
            }

            if (is_string($part['text'] ?? null)) {
                return $part['text'];
            }
        }

        return null;
    }
}
