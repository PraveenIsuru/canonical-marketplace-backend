<?php

declare(strict_types=1);

use App\Contracts\AiProvider;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\AnthropicTransport;
use App\Services\Ai\ConfirmationQuestion;
use App\Services\Ai\GeminiTransport;
use App\Services\Ai\ProductDraft;
use App\Services\Ai\PromptedAiProvider;
use Illuminate\Support\Facades\Http;

/**
 * What the platform asks, and what it will accept as an answer.
 *
 * Every test here runs against both providers, because the point of the class under test
 * is that neither the questions nor the standards change with the vendor. A test that
 * passed for one and not the other would mean the platform behaves differently depending
 * on who is answering, which is the failure this arrangement exists to prevent.
 *
 * Nothing here touches the network.
 */
dataset('providers', ['anthropic', 'gemini']);

function providerFor(string $vendor): PromptedAiProvider
{
    return new PromptedAiProvider(match ($vendor) {
        'anthropic' => new AnthropicTransport('test-key', 'claude-sonnet-4-5', 5),
        'gemini' => new GeminiTransport('test-key', 'gemini-3.5-flash-lite', 5),
    });
}

/** Answers the next call with the given JSON, in whichever envelope the vendor uses. */
function fakeReplyFrom(string $vendor, string $json): void
{
    Http::fake(match ($vendor) {
        'anthropic' => ['api.anthropic.com/*' => Http::response(
            ['content' => [['type' => 'text', 'text' => $json]]],
        )],
        'gemini' => ['generativelanguage.googleapis.com/*' => Http::response(
            ['candidates' => [['content' => ['parts' => [['text' => $json]]], 'finishReason' => 'STOP']]],
        )],
    });
}

/** @return array<int, array<string, mixed>> the parts of the last request sent */
function partsSentTo(string $vendor): array
{
    $body = Http::recorded()->last()[0]->data();

    return $vendor === 'anthropic'
        ? $body['messages'][0]['content']
        : $body['contents'][0]['parts'];
}

function promptSentTo(string $vendor): string
{
    return partsSentTo($vendor)[0]['text'];
}

it('interprets a query and carries the wording into the prompt', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"terms":"wireless headphones","keywords":["wireless","headphones"]}');

    $interpretation = providerFor($vendor)->interpretSearchQuery('something to listen to music on');

    expect($interpretation->terms)->toBe('wireless headphones')
        ->and($interpretation->keywords)->toBe(['wireless', 'headphones'])
        ->and($interpretation->category)->toBeNull()
        ->and(promptSentTo($vendor))->toContain('something to listen to music on');
})->with('providers');

it('rejects a search interpretation with no terms', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"keywords":["wireless"]}');

    expect(fn () => providerFor($vendor)->interpretSearchQuery('anything'))
        ->toThrow(AiUnavailable::class, 'not in the expected shape');
})->with('providers');

it('refuses to guess at a reply that is not the JSON we asked for', function (string $vendor): void {
    fakeReplyFrom($vendor, 'Sure! Here are your search terms.');

    expect(fn () => providerFor($vendor)->interpretSearchQuery('anything'))
        ->toThrow(AiUnavailable::class, 'not in the expected shape');
})->with('providers');

it('spends no call when there is nothing to match against', function (string $vendor): void {
    Http::fake();

    expect(providerFor($vendor)->scoreProductMatches(new ProductDraft('A thing'), []))->toBe([]);

    Http::assertNothingSent();
})->with('providers');

it('drops match candidates outside the shortlist and clamps their scores', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"matches":[
        {"candidate":99,"score":0.9},
        {"candidate":0,"score":0.9},
        {"candidate":-1,"score":0.9},
        {"candidate":2,"score":5},
        {"candidate":1,"score":0.3}
    ]}');

    $shortlist = [
        ['id' => 11, 'name' => 'First', 'description' => null, 'category' => 'Audio'],
        ['id' => 22, 'name' => 'Second', 'description' => 'Second one', 'category' => 'Audio'],
    ];

    $candidates = providerFor($vendor)->scoreProductMatches(new ProductDraft('A thing'), $shortlist);

    expect($candidates)->toHaveCount(2)
        ->and($candidates[0]->productId)->toBe(22)
        ->and($candidates[0]->score)->toBe(1.0)
        ->and($candidates[1]->productId)->toBe(11)
        ->and($candidates[1]->score)->toBe(0.3);
})->with('providers');

it('attaches a draft image to the matching call', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"matches":[]}');

    $path = tempnam(sys_get_temp_dir(), 'ai').'.png';
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    providerFor($vendor)->scoreProductMatches(
        new ProductDraft('A thing', imagePath: $path),
        [['id' => 11, 'name' => 'First', 'description' => null, 'category' => 'Audio']],
    );

    expect(partsSentTo($vendor))->toHaveCount(2);

    unlink($path);
})->with('providers');

it('fails before spending a call when the draft image cannot be read', function (string $vendor): void {
    Http::fake();

    expect(fn () => providerFor($vendor)->scoreProductMatches(
        new ProductDraft('A thing', imagePath: '/no/such/file.png'),
        [['id' => 11, 'name' => 'First', 'description' => null, 'category' => 'Audio']],
    ))->toThrow(AiUnavailable::class, 'the uploaded image could not be read');

    Http::assertNothingSent();
})->with('providers');

it('numbers wizard questions itself rather than trusting the reply', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"questions":[
        {"id":"banana","attribute":"brand","text":"Who makes it?"},
        {"id":"banana","text":"What is it made of?"}
    ]}');

    $questions = providerFor($vendor)->generateWizardQuestions(new ProductDraft('A thing'));

    expect($questions)->toHaveCount(2)
        ->and($questions[0]->id)->toBe('q1')
        ->and($questions[0]->attribute)->toBe('brand')
        ->and($questions[1]->id)->toBe('q2')
        ->and($questions[1]->attribute)->toBe('detail_2');
})->with('providers');

it('treats a wizard with no usable questions as a failure', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"questions":[]}');

    expect(fn () => providerFor($vendor)->generateWizardQuestions(new ProductDraft('A thing')))
        ->toThrow(AiUnavailable::class, 'no usable questions');
})->with('providers');

it('asks about every field even when the reply covers only some of them', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"questions":[
        {"attribute":"name","text":"What is it called?"},
        {"attribute":"inputs","text":"What does it plug into?"}
    ]}');

    $product = Product::factory()->create([
        'name' => 'Studio Monitor',
        'category' => 'Audio',
        'description' => 'A speaker.',
        'specifications' => ['inputs' => 'XLR', 'weight' => '2kg'],
    ]);
    ProductAttribute::factory()->named('Colour', ['Black', 'White'])->create(['product_id' => $product->id]);

    $questions = providerFor($vendor)->generateConfirmationQuestions($product->fresh());

    expect($questions)->toHaveCount(6)
        ->and(array_map(fn (ConfirmationQuestion $q): string => $q->id, $questions))
        ->toBe(['q1', 'q2', 'q3', 'q4', 'q5', 'q6'])
        ->and(array_map(fn (ConfirmationQuestion $q): string => $q->attribute, $questions))
        ->toBe(['name', 'category', 'description', 'inputs', 'weight', 'Colour']);

    expect($questions[0]->text)->toBe('What is it called?')
        ->and($questions[3]->text)->toBe('What does it plug into?');

    // The fallback is what guarantees coverage: an attribute nobody is asked about can
    // never be corrected.
    expect($questions[1]->text)->toBe('What is the category of the unit you stock?')
        ->and($questions[5]->text)->toBe('What is the Colour of the unit you stock?');

    expect($questions[1]->currentValue)->toBe('Audio')
        ->and($questions[5]->currentValue)->toBe('Black, White');
})->with('providers');

it('clamps a confidence score into range', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"score":1.4,"reason":"Very specific answers."}');

    $assessment = providerFor($vendor)->scoreConfirmationAnswers(
        [new ConfirmationQuestion('q1', 'name', 'What is it called?')],
        ['q1' => 'A Studio Monitor'],
    );

    expect($assessment->score)->toBe(1.0)
        ->and($assessment->reason)->toBe('Very specific answers.');
})->with('providers');

it('treats a missing confidence score as a failure', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"reason":"I would rather not say."}');

    expect(fn () => providerFor($vendor)->scoreConfirmationAnswers(
        [new ConfirmationQuestion('q1', 'name', 'What is it called?')],
        ['q1' => 'A Studio Monitor'],
    ))->toThrow(AiUnavailable::class, 'no usable confidence score');
})->with('providers');

it('sends a verification photograph with the code and reads the verdict', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"passed":true,"reason":"The code is legible beside the product."}');

    $assessment = providerFor($vendor)
        ->verifyOwnership(Product::factory()->create(), 'AB12CD', 'RAWBYTES', 'image/jpeg');

    expect($assessment->passed)->toBeTrue()
        ->and($assessment->reason)->toBe('The code is legible beside the product.')
        ->and(promptSentTo($vendor))->toContain('AB12CD')
        ->and(partsSentTo($vendor))->toHaveCount(2);
})->with('providers');

it('supplies a verification reason when the reply gives none', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"passed":false}');

    $assessment = providerFor($vendor)
        ->verifyOwnership(Product::factory()->create(), 'AB12CD', 'BYTES', 'image/jpeg');

    expect($assessment->passed)->toBeFalse()
        ->and($assessment->reason)->toBe('The photograph did not clearly show the code beside the product.');
})->with('providers');

it('returns a community summary as plain text', function (string $vendor): void {
    fakeReplyFrom($vendor, '{"summary":"  Owners agree it is loud.  "}');

    $summary = providerFor($vendor)
        ->summariseCommunity(Product::factory()->create(), ['Very loud', 'Loud but clear']);

    expect($summary)->toBe('Owners agree it is loud.');
})->with('providers');

/**
 * The guarantee the whole arrangement rests on.
 *
 * Every method is run through both transports with identical inputs and the two prompts
 * are compared byte for byte. If the prompts are ever copied into a vendor class so they
 * can be tuned for one provider, this is what notices.
 *
 * The replies are deliberately useless, because only the outgoing request matters here.
 * A method that throws on the way back has already sent the prompt, so the failure is
 * caught and ignored rather than worked around with seven different valid replies.
 */
it('sends byte identical prompts to every provider', function (): void {
    $product = Product::factory()->create([
        'name' => 'Studio Monitor',
        'category' => 'Audio',
        'description' => 'A speaker.',
        'specifications' => ['inputs' => 'XLR'],
    ]);
    ProductAttribute::factory()->named('Colour', ['Black', 'White'])->create(['product_id' => $product->id]);
    $product = $product->fresh();

    $draft = new ProductDraft('A thing', 'Described here', 'Audio');
    $shortlist = [['id' => 11, 'name' => 'First', 'description' => 'One', 'category' => 'Audio']];
    $questions = [new ConfirmationQuestion('q1', 'name', 'What is it called?')];

    $calls = [
        'interpretSearchQuery' => fn (PromptedAiProvider $ai) => $ai->interpretSearchQuery('a query'),
        'scoreProductMatches' => fn (PromptedAiProvider $ai) => $ai->scoreProductMatches($draft, $shortlist),
        'generateWizardQuestions' => fn (PromptedAiProvider $ai) => $ai->generateWizardQuestions($draft),
        'generateConfirmationQuestions' => fn (PromptedAiProvider $ai) => $ai->generateConfirmationQuestions($product),
        'scoreConfirmationAnswers' => fn (PromptedAiProvider $ai) => $ai->scoreConfirmationAnswers($questions, ['q1' => 'A monitor']),
        'verifyOwnership' => fn (PromptedAiProvider $ai) => $ai->verifyOwnership($product, 'AB12CD', 'BYTES', 'image/jpeg'),
        'summariseCommunity' => fn (PromptedAiProvider $ai) => $ai->summariseCommunity($product, ['Very loud']),
    ];

    $prompts = [];

    foreach (['anthropic', 'gemini'] as $vendor) {
        foreach ($calls as $method => $call) {
            fakeReplyFrom($vendor, '{}');

            try {
                $call(providerFor($vendor));
            } catch (AiUnavailable) {
                // The prompt was sent before the reply was judged, which is all we need.
            }

            $prompts[$method][$vendor] = promptSentTo($vendor);
        }
    }

    foreach ($calls as $method => $_) {
        expect($prompts[$method]['gemini'])
            ->toBe($prompts[$method]['anthropic'], "{$method} sent a different prompt to each provider");
    }

    // A guard against the loop above passing because nothing was ever recorded.
    expect($prompts)->toHaveCount(7)
        ->and($prompts['verifyOwnership']['gemini'])->toContain('AB12CD');
});

it('builds the configured provider from the container', function (string $vendor): void {
    config()->set('ai.provider', $vendor);
    // The binding is a singleton, so a provider resolved earlier in the test run would
    // otherwise be handed back regardless of the config just set.
    app()->forgetInstance(AiProvider::class);

    expect(app(AiProvider::class))->toBeInstanceOf(PromptedAiProvider::class);
})->with('providers');

it('refuses to boot on a provider nobody configured', function (): void {
    config()->set('ai.provider', 'gpt5');
    app()->forgetInstance(AiProvider::class);

    expect(fn () => app(AiProvider::class))
        ->toThrow(InvalidArgumentException::class, 'Supported: fake, anthropic, gemini');
});
