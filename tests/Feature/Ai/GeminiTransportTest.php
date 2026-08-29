<?php

declare(strict_types=1);

use App\Services\Ai\AiImage;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\GeminiTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini's wire format, against a faked HTTP client.
 *
 * Gemini fails in three ways Anthropic does not, and all three arrive as HTTP 200: a
 * blocked prompt, a reply cut off by the token budget, and a candidate stopped part way.
 * None of them look like failures to the shared HTTP handling, so each is asserted here.
 */
function geminiTransport(): GeminiTransport
{
    return new GeminiTransport('test-key', 'gemini-3.5-flash-lite', 5);
}

/** @param array<int, array<string, mixed>> $parts */
function geminiReply(array $parts, string $finishReason = 'STOP'): array
{
    return ['candidates' => [[
        'content' => ['parts' => $parts],
        'finishReason' => $finishReason,
    ]]];
}

it('posts to generateContent with the key in a header', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiReply([['text' => '{"ok":true}']]),
    )]);

    expect(geminiTransport()->ask(AiRequest::for('Say something', 256)))->toBe('{"ok":true}');

    Http::assertSent(function ($request): bool {
        expect($request->url())->toBe(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent',
        )
            ->and($request->header('x-goog-api-key'))->toBe(['test-key'])
            // The key must never reach a URL, where an access log or an exception trace
            // would carry it out of the application.
            ->and($request->url())->not->toContain('test-key');

        $body = $request->data();

        expect($body['contents'])->toBe([['role' => 'user', 'parts' => [['text' => 'Say something']]]])
            ->and($body['generationConfig']['responseMimeType'])->toBe('application/json')
            // Left to the model's own default, because the levels are not supported
            // uniformly and forcing one would break other models outright.
            ->and($body['generationConfig'])->not->toHaveKey('thinkingConfig');

        return true;
    });
});

it('adds headroom for reasoning on top of the answer we asked for', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiReply([['text' => '{"ok":true}']]),
    )]);

    geminiTransport()->ask(AiRequest::for('Say something', 256));

    Http::assertSent(function ($request): bool {
        expect($request->data()['generationConfig']['maxOutputTokens'])->toBe(256 + 2048);

        return true;
    });
});

it('sends an image as a snake case inline_data part', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiReply([['text' => '{"ok":true}']]),
    )]);

    geminiTransport()->ask(
        AiRequest::for('Look at this', 256)->withImage(AiImage::fromBytes('RAWBYTES', 'image/png')),
    );

    Http::assertSent(function ($request): bool {
        $parts = $request->data()['contents'][0]['parts'];

        expect($parts[0])->toBe(['text' => 'Look at this'])
            ->and($parts[1])->toBe(['inline_data' => [
                'mime_type' => 'image/png',
                'data' => base64_encode('RAWBYTES'),
            ]]);

        return true;
    });
});

it('skips a part carrying the model reasoning rather than its answer', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiReply([
            ['thought' => true, 'text' => 'Let me consider this.'],
            ['text' => '{"ok":true}'],
        ]),
    )]);

    expect(geminiTransport()->ask(AiRequest::for('Anything', 256)))->toBe('{"ok":true}');
});

it('reports a reply cut off by the token budget as such', function (): void {
    // The candidate carries no parts at all, which is what a budget spent on reasoning
    // looks like. Reported plainly so it cannot be mistaken for an outage.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        ['candidates' => [['finishReason' => 'MAX_TOKENS']]],
    )]);

    expect(fn () => geminiTransport()->ask(AiRequest::for('Anything', 256)))
        ->toThrow(AiUnavailable::class, 'cut off before it was complete');
});

it('names the reason when the prompt itself was blocked', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        ['promptFeedback' => ['blockReason' => 'SAFETY']],
    )]);

    expect(fn () => geminiTransport()->ask(AiRequest::for('Anything', 256)))
        ->toThrow(AiUnavailable::class, 'blocked the request (SAFETY)');
});

it('names the reason when a candidate was stopped part way', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiReply([], 'PROHIBITED_CONTENT'),
    )]);

    expect(fn () => geminiTransport()->ask(AiRequest::for('Anything', 256)))
        ->toThrow(AiUnavailable::class, 'no text content (PROHIBITED_CONTENT)');
});

it('treats a reply with no candidates at all as a failure', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => []])]);

    expect(fn () => geminiTransport()->ask(AiRequest::for('Anything', 256)))
        ->toThrow(AiUnavailable::class, 'no text content');
});

it('reports an unreachable host rather than letting the client exception escape', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    expect(fn () => geminiTransport()->ask(AiRequest::for('Anything', 256)))
        ->toThrow(AiUnavailable::class, 'timed out or the host was unreachable');
});

it('carries the provider reason out of a refused request', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        ['error' => ['code' => 400, 'message' => 'API key not valid', 'status' => 'INVALID_ARGUMENT']], 400,
    )]);

    try {
        geminiTransport()->ask(AiRequest::for('Anything', 256));
    } catch (AiUnavailable $e) {
        expect($e->getMessage())->toContain('HTTP 400')
            ->and($e->getMessage())->toContain('API key not valid');

        return;
    }

    $this->fail('A refused request should have been reported as AiUnavailable.');
});
