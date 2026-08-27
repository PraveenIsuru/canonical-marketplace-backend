<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * One question asked of a seller attaching to a record that already exists.
 *
 * `attribute` names the field on the record the answer will be compared against, for
 * example `name`, or a specification key like `inputs`, or a variant attribute like
 * `Colour`. That mapping is what makes the comparison at submit possible at all: an
 * answer with no field to compare against cannot become a proposed change.
 *
 * `current_value` is what the record holds today. It is carried so the comparison does
 * not have to re-read the product and risk comparing against a record that moved while
 * the seller was answering.
 */
final readonly class ConfirmationQuestion
{
    public function __construct(
        public string $id,
        public string $attribute,
        public string $text,
        public ?string $currentValue = null,
    ) {}

    /** @return array{id: string, attribute: string, text: string, current_value: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'attribute' => $this->attribute,
            'text' => $this->text,
            /*
             * Stored on the session but **not** returned to the client.
             *
             * Showing the seller the answer we expect would turn confirmation into a
             * yes or no exercise, and the whole value of the flow is that the seller
             * says what the product is without being led to the record's version of it.
             */
            'current_value' => $this->currentValue,
        ];
    }

    /** @param  array<string, mixed>  $question */
    public static function fromArray(array $question): self
    {
        return new self(
            id: (string) ($question['id'] ?? ''),
            attribute: (string) ($question['attribute'] ?? ''),
            text: (string) ($question['text'] ?? ''),
            currentValue: is_string($question['current_value'] ?? null) ? $question['current_value'] : null,
        );
    }
}
