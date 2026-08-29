<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic's half of a provider call.
 *
 * The only class in the application that knows Anthropic exists. It carries no prompt
 * and makes no judgement about an answer: it turns a neutral request into the shape the
 * Messages API expects and hands back whatever text came out.
 */
final class AnthropicTransport implements AiTransport
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

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
        /** @var array<int, array<string, mixed>> $content */
        $content = [['type' => 'text', 'text' => $request->prompt]];

        foreach ($request->images as $image) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $image->mimeType,
                    'data' => $image->base64(),
                ],
            ];
        }

        $response = ProviderCall::orFail(fn (): Response => Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout($this->timeoutSeconds)
            ->post(self::ENDPOINT, [
                'model' => $this->model,
                'max_tokens' => $request->maxTokens,
                'messages' => [['role' => 'user', 'content' => $content]],
            ]));

        $text = $response->json('content.0.text');

        if (! is_string($text)) {
            throw AiUnavailable::because('the provider returned no text content');
        }

        return $text;
    }
}
