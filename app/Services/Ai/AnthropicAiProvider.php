<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The real provider adapter.
 *
 * The only class in the application that knows Anthropic exists. Every feature depends
 * on the AiProvider interface, so switching vendor is a config change plus one new
 * class in this directory.
 *
 * No test exercises this class against the network. Tests bind FakeAiProvider instead,
 * which is what makes the suite runnable offline and free.
 */
final class AnthropicAiProvider implements AiProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        /**
         * Short on purpose. A slow provider must degrade to keyword search rather than
         * hang the request, and buyer search is the availability floor for discovery.
         */
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function interpretSearchQuery(string $query): SearchInterpretation
    {
        $prompt = <<<PROMPT
            A shopper typed the following into a product search box. Extract the search
            terms that would find the product they mean, dropping filler words.

            Reply with JSON only, in the form {"terms": "...", "keywords": ["..."]}.

            Query: {$query}
            PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout($this->timeoutSeconds)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 256,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            throw AiUnavailable::because('the request timed out or the host was unreachable');
        } catch (Throwable $e) {
            throw AiUnavailable::because($e->getMessage());
        }

        if ($response->failed()) {
            throw AiUnavailable::because("the provider returned HTTP {$response->status()}");
        }

        $text = $response->json('content.0.text');

        if (! is_string($text)) {
            throw AiUnavailable::because('the provider returned no text content');
        }

        $decoded = json_decode($text, true);

        // A reply that is not the JSON we asked for is a failure, not something to
        // guess at. Guessing would hand the index a malformed query.
        if (! is_array($decoded) || ! isset($decoded['terms']) || ! is_string($decoded['terms'])) {
            throw AiUnavailable::because('the provider reply was not in the expected shape');
        }

        $keywords = array_values(array_filter(
            (array) ($decoded['keywords'] ?? []),
            static fn ($keyword): bool => is_string($keyword),
        ));

        return new SearchInterpretation(
            terms: $decoded['terms'],
            keywords: $keywords,
            category: is_string($decoded['category'] ?? null) ? $decoded['category'] : null,
        );
    }
}
