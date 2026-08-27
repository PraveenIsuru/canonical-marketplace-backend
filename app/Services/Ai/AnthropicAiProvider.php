<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Models\Product;
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

        $decoded = $this->ask([['type' => 'text', 'text' => $prompt]], maxTokens: 256);

        if (! isset($decoded['terms']) || ! is_string($decoded['terms'])) {
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

    /**
     * Asks the model to judge a shortlist the application already retrieved.
     *
     * Candidates are numbered in the prompt and the reply refers to them by that number
     * rather than by product id, so an invented id cannot reach the database. Anything
     * outside the supplied range is dropped.
     */
    public function scoreProductMatches(ProductDraft $draft, array $shortlist): array
    {
        if ($shortlist === []) {
            // Nothing to judge. Spending a provider call to be told so would be a bill
            // for an answer already known.
            return [];
        }

        $shortlist = array_values($shortlist);
        $lines = [];

        foreach ($shortlist as $index => $product) {
            $lines[] = sprintf(
                '%d. %s (category: %s) %s',
                $index + 1,
                $product['name'],
                $product['category'],
                (string) ($product['description'] ?? ''),
            );
        }

        $numbered = implode("\n", $lines);
        $described = trim($draft->name."\n".(string) $draft->description);

        $prompt = <<<PROMPT
            A seller wants to list a product. Decide which, if any, of the numbered
            existing products are the same product they are describing.

            Judge the product itself, not the wording. Different colours, capacities, or
            sizes of the same model ARE the same product. A different model is not.

            Reply with JSON only, in the form
            {"matches": [{"candidate": 1, "score": 0.94}]}.
            Score from 0 to 1. Return an empty array where none is the same product.

            The product being listed:
            {$described}

            Existing products:
            {$numbered}
            PROMPT;

        $content = [['type' => 'text', 'text' => $prompt]];

        // Matching operates on text and images, so the image travels in the same
        // message when one was supplied. This is what makes the interface vision bound.
        if ($draft->imagePath !== null) {
            $content[] = $this->imageBlock($draft->imagePath);
        }

        $decoded = $this->ask($content, maxTokens: 512);

        $matches = is_array($decoded['matches'] ?? null) ? $decoded['matches'] : [];
        $candidates = [];

        foreach ($matches as $match) {
            if (! is_array($match)) {
                continue;
            }

            $position = (int) ($match['candidate'] ?? 0);

            // One based in the prompt, and only ever a position within what we sent.
            if ($position < 1 || $position > count($shortlist)) {
                continue;
            }

            $candidates[] = new ProductMatchCandidate(
                productId: $shortlist[$position - 1]['id'],
                score: max(0.0, min(1.0, (float) ($match['score'] ?? 0))),
            );
        }

        usort($candidates, static fn (ProductMatchCandidate $a, ProductMatchCandidate $b): int => $b->score <=> $a->score);

        return $candidates;
    }

    public function generateWizardQuestions(ProductDraft $draft): array
    {
        $described = trim($draft->name."\n".(string) $draft->description);
        $category = $draft->category ?? 'not stated';

        $prompt = <<<PROMPT
            A seller is listing a product that is not yet in the catalogue. Write the
            questions that would establish what it is.

            Write them from the point of view of a buyer: what someone shopping for this
            would want to know before choosing it. Do not ask about price, stock, or
            delivery, or anything else specific to one seller, because the answers
            describe the product itself and are shared by every seller who later lists it.

            Ask between four and eight questions. Reply with JSON only, in the form
            {"questions": [{"id": "q1", "attribute": "brand", "text": "..."}]}.
            `attribute` is a short snake_case name for the fact the question establishes.

            Product: {$described}
            Category: {$category}
            PROMPT;

        $decoded = $this->ask([['type' => 'text', 'text' => $prompt]], maxTokens: 1024);

        $questions = is_array($decoded['questions'] ?? null) ? $decoded['questions'] : [];
        $generated = [];

        foreach (array_values($questions) as $index => $question) {
            if (! is_array($question) || ! is_string($question['text'] ?? null)) {
                continue;
            }

            $generated[] = new WizardQuestion(
                // Numbered here rather than trusting the reply, because duplicate ids
                // would silently collapse two questions into one answer slot.
                id: 'q'.($index + 1),
                attribute: is_string($question['attribute'] ?? null) ? $question['attribute'] : 'detail_'.($index + 1),
                text: $question['text'],
            );
        }

        // An empty question set is not a usable wizard, so it counts as the provider
        // having failed rather than as a product with nothing worth asking about.
        if ($generated === []) {
            throw AiUnavailable::because('the provider returned no usable questions');
        }

        return $generated;
    }

    /**
     * Asks for one question per field the record holds.
     *
     * The fields are enumerated in the prompt rather than left to the model to recall,
     * because coverage is the requirement: every attribute is confirmed every time,
     * without exception. Anything the reply misses is filled in afterwards with a plain
     * question, so a terse model cannot quietly narrow the flow.
     */
    public function generateConfirmationQuestions(Product $product): array
    {
        $fields = $this->fieldsOf($product);

        $listed = implode("\n", array_map(
            static fn (array $field): string => "- {$field['attribute']}: {$field['current']}",
            $fields,
        ));

        $prompt = <<<PROMPT
            A seller says they stock this product and we are checking they mean the same
            product the catalogue describes. Write one question for each field below.

            Ask what the seller's own unit is, in a way that would reveal a genuine
            difference. Do **not** put the current value in the question: quoting it
            invites the seller to agree rather than to look at what they have.

            Reply with JSON only, in the form
            {"questions": [{"attribute": "name", "text": "..."}]}.
            Use each attribute name below exactly as written.

            Product: {$product->name}

            Fields:
            {$listed}
            PROMPT;

        $decoded = $this->ask([['type' => 'text', 'text' => $prompt]], maxTokens: 1500);

        $written = [];

        foreach ((array) ($decoded['questions'] ?? []) as $question) {
            if (is_array($question) && is_string($question['attribute'] ?? null) && is_string($question['text'] ?? null)) {
                $written[$question['attribute']] = $question['text'];
            }
        }

        $questions = [];

        foreach ($fields as $index => $field) {
            $questions[] = new ConfirmationQuestion(
                // Numbered here rather than trusting the reply, because duplicate ids
                // would collapse two questions into one answer slot.
                id: 'q'.($index + 1),
                attribute: $field['attribute'],
                /*
                 * The fallback is what guarantees coverage. A model that returned four
                 * questions for six fields would otherwise leave two attributes
                 * unconfirmed, and an attribute nobody is asked about can never be
                 * corrected.
                 */
                text: $written[$field['attribute']]
                    ?? sprintf('What is the %s of the unit you stock?', str_replace('_', ' ', $field['attribute'])),
                currentValue: $field['current'],
            );
        }

        return $questions;
    }

    public function scoreConfirmationAnswers(array $questions, array $answers): ConfidenceAssessment
    {
        $transcript = implode("\n\n", array_map(
            static fn (ConfirmationQuestion $question): string => sprintf(
                "Q (%s): %s\nA: %s",
                $question->attribute,
                $question->text,
                $answers[$question->id] ?? '',
            ),
            $questions,
        ));

        $prompt = <<<PROMPT
            A seller answered these questions about a product they say they stock. Judge
            how much confidence the answers themselves warrant.

            Score on substance and internal consistency: specific answers that agree
            with one another score high, vague or contradictory ones score low.

            **Do not score on whether the seller agrees with any existing record.** A
            seller who describes something different may simply have better information,
            and that disagreement is the reason this process exists.

            Reply with JSON only, in the form {"score": 0.82, "reason": "..."}.
            Score from 0 to 1.

            {$transcript}
            PROMPT;

        $decoded = $this->ask([['type' => 'text', 'text' => $prompt]], maxTokens: 512);

        if (! isset($decoded['score']) || ! is_numeric($decoded['score'])) {
            throw AiUnavailable::because('the provider returned no usable confidence score');
        }

        return new ConfidenceAssessment(
            score: max(0.0, min(1.0, (float) $decoded['score'])),
            reason: is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '',
        );
    }

    /**
     * Every field on the record that has to be confirmed.
     *
     * Core fields, then every specification key, then every variant attribute. The
     * order is stable so question ids mean the same thing across a retry.
     *
     * @return array<int, array{attribute: string, current: string}>
     */
    private function fieldsOf(Product $product): array
    {
        $fields = [
            ['attribute' => 'name', 'current' => $product->name],
            ['attribute' => 'category', 'current' => $product->category],
        ];

        if ($product->description !== null && $product->description !== '') {
            $fields[] = ['attribute' => 'description', 'current' => $product->description];
        }

        foreach ($product->specifications ?? [] as $key => $value) {
            $fields[] = [
                'attribute' => (string) $key,
                'current' => is_scalar($value) ? (string) $value : '',
            ];
        }

        foreach ($product->productAttributes as $attribute) {
            $fields[] = [
                'attribute' => $attribute->name,
                'current' => implode(', ', $attribute->options),
            ];
        }

        return $fields;
    }

    /**
     * One request, one decoded JSON reply.
     *
     * Every call this adapter makes fails in the same handful of ways, and handling
     * them once keeps a newly added method from quietly omitting one.
     *
     * @param  array<int, array<string, mixed>>  $content
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    private function ask(array $content, int $maxTokens): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout($this->timeoutSeconds)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ]);
        } catch (ConnectionException) {
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
        // guess at. Guessing would put invented facts onto a canonical record.
        if (! is_array($decoded)) {
            throw AiUnavailable::because('the provider reply was not in the expected shape');
        }

        return $decoded;
    }

    /**
     * An uploaded image, inlined as base64 for the vision request.
     *
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    private function imageBlock(string $path): array
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw AiUnavailable::because('the uploaded image could not be read');
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => (string) (mime_content_type($path) ?: 'image/jpeg'),
                'data' => base64_encode($bytes),
            ],
        ];
    }
}
