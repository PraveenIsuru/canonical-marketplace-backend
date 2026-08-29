<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Models\Product;

/**
 * Every prompt the platform sends, and every reply it will accept.
 *
 * This class knows no vendor. It writes the questions, checks what comes back, and hands
 * the wire to a transport, which is why the same seven prompts serve whichever provider
 * configuration names. A second provider is a second transport, and can never be a
 * second copy of the prompts: they would drift the first time one was improved, and the
 * platform would quietly behave differently depending on who was answering.
 *
 * No test exercises this class against a network. The transport tests fake the HTTP
 * client, and everything else binds FakeAiProvider, which is what makes the suite
 * runnable offline and free.
 */
final class PromptedAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiTransport $transport,
    ) {}

    public function interpretSearchQuery(string $query): SearchInterpretation
    {
        $prompt = <<<PROMPT
            A shopper typed the following into a product search box. Extract the search
            terms that would find the product they mean, dropping filler words.

            Reply with JSON only, in the form {"terms": "...", "keywords": ["..."]}.

            Query: {$query}
            PROMPT;

        $decoded = $this->ask(AiRequest::for($prompt, maxTokens: 256));

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

        $request = AiRequest::for($prompt, maxTokens: 512);

        // Matching operates on text and images, so the image travels in the same
        // message when one was supplied. This is what makes the interface vision bound.
        if ($draft->imagePath !== null) {
            $request = $request->withImage(AiImage::fromPath($draft->imagePath));
        }

        $decoded = $this->ask($request);

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

        $decoded = $this->ask(AiRequest::for($prompt, maxTokens: 1024));

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

        $decoded = $this->ask(AiRequest::for($prompt, maxTokens: 1500));

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

        $decoded = $this->ask(AiRequest::for($prompt, maxTokens: 512));

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
     * One request, one decoded JSON object.
     *
     * The transport hands back whatever the model wrote. Deciding whether that is the
     * JSON we asked for is a judgement about the answer rather than about the wire, so
     * it happens here, once, and applies to every provider alike.
     *
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    private function ask(AiRequest $request): array
    {
        $decoded = json_decode($this->transport->ask($request), true);

        // A reply that is not the JSON we asked for is a failure, not something to
        // guess at. Guessing would put invented facts onto a canonical record.
        if (! is_array($decoded)) {
            throw AiUnavailable::because('the provider reply was not in the expected shape');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Judge a verification photograph.
     *
     * Both halves are checked in one call and the prompt says so explicitly, because a
     * model asked only "is this the product" will happily pass a photograph with no
     * code in it, and the code is the entire reason the photograph is evidence of
     * present possession rather than a picture from the internet.
     */
    public function verifyOwnership(
        Product $product,
        string $code,
        string $photo,
        string $mimeType,
    ): OwnershipAssessment {
        $specifications = json_encode($product->specifications ?? [], JSON_THROW_ON_ERROR);

        $prompt = <<<PROMPT
            You are checking whether somebody physically has a product.

            They were asked to write a code on paper and photograph it beside the
            product. Both must be true for a pass:

            1. The handwritten code in the photograph reads exactly {$code}.
            2. The product in the photograph is this product.

            A photograph showing only the code passes neither test. A photograph of a
            different product with the right code fails. A screenshot or a product
            photograph from a website fails: we are checking possession, not knowledge.

            Be forgiving about handwriting, lighting, and angle. Be strict about which
            product it is and about the code being present and correct.

            The product:
            Name: {$product->name}
            Category: {$product->category}
            Description: {$product->description}
            Specifications: {$specifications}

            Reply with JSON only, in the form
            {"passed": true, "reason": "one short sentence for the buyer"}.

            The reason is shown to the person who submitted the photograph, so write it
            to them. Say what was missing or wrong. Do not describe your reasoning.
            PROMPT;

        $decoded = $this->ask(
            AiRequest::for($prompt, maxTokens: 256)
                // Bytes, not a path. Nothing here learns where the file lived, and the
                // caller deletes it the moment this returns.
                ->withImage(AiImage::fromBytes($photo, $mimeType)),
        );

        $passed = (bool) ($decoded['passed'] ?? false);
        $reason = trim((string) ($decoded['reason'] ?? ''));

        if ($reason === '') {
            $reason = $passed
                ? 'The code and the product are both visible.'
                : 'The photograph did not clearly show the code beside the product.';
        }

        return $passed ? OwnershipAssessment::passed($reason) : OwnershipAssessment::failed($reason);
    }

    /**
     * Summarise a product's discussion.
     *
     * Told plainly not to produce a rating. The platform has no star score anywhere,
     * and a model left to its own devices will reach for one.
     */
    public function summariseCommunity(Product $product, array $posts): string
    {
        $joined = implode('

', array_map(
            static fn (string $body): string => '- '.trim($body),
            $posts,
        ));

        $prompt = <<<PROMPT
            Summarise what owners are saying about {$product->name}.

            Write two or three sentences describing what recurs: what owners agree on,
            what they disagree about, and any problem mentioned more than once.

            Do not give a rating, a score, a star count, or a recommendation. Do not
            invent anything that is not in the comments. Where the comments are too few
            or too varied to summarise, say so plainly.

            Write it as a standing description of the discussion, not as a reply to the
            newest comment.

            The comments:
            {$joined}

            Reply with JSON only, in the form {"summary": "..."}.
            PROMPT;

        $decoded = $this->ask(AiRequest::for($prompt, maxTokens: 512));

        return trim((string) ($decoded['summary'] ?? ''));
    }
}
