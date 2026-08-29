<?php

declare(strict_types=1);

use App\Services\Ai\AiImage;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\AnthropicTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic's wire format, against a faked HTTP client.
 *
 * Only what is vendor specific belongs here: the endpoint, the headers, the request
 * shape and the handful of ways a call can fail. What the platform asks and what it will
 * accept as an answer is the same for every provider and is tested once, in
 * PromptedAiProviderTest.
 */
function anthropicTransport(): AnthropicTransport
{
    return new AnthropicTransport('test-key', 'claude-sonnet-4-5', 5);
}

it('posts the documented request shape', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(
        ['content' => [['type' => 'text', 'text' => '{"ok":true}']]],
    )]);

    expect(anthropicTransport()->ask(AiRequest::for('Say something', 256)))->toBe('{"ok":true}');

    Http::assertSent(function ($request): bool {
        expect($request->url())->toBe('https://api.anthropic.com/v1/messages')
            ->and($request->header('x-api-key'))->toBe(['test-key'])
            ->and($request->header('anthropic-version'))->toBe(['2023-06-01']);

        $body = $request->data();

        expect($body['model'])->toBe('claude-sonnet-4-5')
            ->and($body['max_tokens'])->toBe(256)
            ->and($body['messages'][0]['role'])->toBe('user')
            ->and($body['messages'][0]['content'])->toBe([
                ['type' => 'text', 'text' => 'Say something'],
            ]);

        return true;
    });
});

it('sends an image as a base64 source block after the text', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(
        ['content' => [['type' => 'text', 'text' => '{"ok":true}']]],
    )]);

    anthropicTransport()->ask(
        AiRequest::for('Look at this', 256)->withImage(AiImage::fromBytes('RAWBYTES', 'image/png')),
    );

    Http::assertSent(function ($request): bool {
        $content = $request->data()['messages'][0]['content'];

        expect($content[0]['type'])->toBe('text')
            ->and($content[1])->toBe([
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/png',
                    'data' => base64_encode('RAWBYTES'),
                ],
            ]);

        return true;
    });
});

it('reports an unreachable host rather than letting the client exception escape', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    expect(fn () => anthropicTransport()->ask(AiRequest::for('Anything', 256)))
        ->toThrow(AiUnavailable::class, 'timed out or the host was unreachable');
});

it('carries the provider reason out of a refused request', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(
        ['error' => ['message' => 'Your credit balance is too low']], 400,
    )]);

    try {
        anthropicTransport()->ask(AiRequest::for('Anything', 256));
    } catch (AiUnavailable $e) {
        expect($e->getMessage())->toContain('HTTP 400')
            ->and($e->getMessage())->toContain('Your credit balance is too low');

        return;
    }

    $this->fail('A refused request should have been reported as AiUnavailable.');
});

it('caps a reply that is not the documented error shape', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response('<html>'.str_repeat('x', 900).'</html>', 500)]);

    try {
        anthropicTransport()->ask(AiRequest::for('Anything', 256));
    } catch (AiUnavailable $e) {
        expect($e->getMessage())->toContain('HTTP 500')
            ->and(strlen($e->getMessage()))->toBeLessThan(400);

        return;
    }

    $this->fail('An HTTP 500 should have been reported as AiUnavailable.');
});

it('treats a reply with no text content as a failure', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);

    expect(fn () => anthropicTransport()->ask(AiRequest::for('Anything', 256)))
        ->toThrow(AiUnavailable::class, 'no text content');
});
