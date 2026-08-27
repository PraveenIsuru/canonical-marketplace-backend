<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The product details a seller typed before any AI call was made.
 *
 * The same three fields drive matching and wizard question generation, so they travel
 * as one object rather than as three parameters repeated across two interface methods.
 *
 * `imagePath` is an absolute path to a temporarily stored upload. An image submitted
 * for matching is transient and is never kept as a product image, so nothing here
 * implies storage.
 */
final readonly class ProductDraft
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $category = null,
        public ?string $imagePath = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
        ];
    }

    /** @param  array<string, mixed>  $draft */
    public static function fromArray(array $draft): self
    {
        return new self(
            name: (string) ($draft['name'] ?? ''),
            description: is_string($draft['description'] ?? null) ? $draft['description'] : null,
            category: is_string($draft['category'] ?? null) ? $draft['category'] : null,
        );
    }
}
