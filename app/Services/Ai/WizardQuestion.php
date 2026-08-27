<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * One question put to a seller during the listing wizard.
 *
 * `attribute` names the fact the question is trying to establish, for example "inputs"
 * or "material". It is what lets an answer be filed against the right specification
 * rather than kept as loose text.
 *
 * `id` is what the client sends answers back against, so it must be stable for the
 * life of the session.
 */
final readonly class WizardQuestion
{
    public function __construct(
        public string $id,
        public string $attribute,
        public string $text,
    ) {}

    /** @return array{id: string, attribute: string, text: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'attribute' => $this->attribute, 'text' => $this->text];
    }

    /** @param  array<string, mixed>  $question */
    public static function fromArray(array $question): self
    {
        return new self(
            id: (string) ($question['id'] ?? ''),
            attribute: (string) ($question['attribute'] ?? ''),
            text: (string) ($question['text'] ?? ''),
        );
    }
}
