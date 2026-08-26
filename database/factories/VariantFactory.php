<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $values = ['Colour' => $this->faker->randomElement(['Black', 'White', 'Blue'])];

        return [
            'product_id' => Product::factory(),
            'attribute_values' => $values,
            'combination_hash' => Variant::hashCombination($values),
            'is_default' => false,
        ];
    }

    /** The single default variant of a product with no attributes at all. */
    public function default(): static
    {
        return $this->state(fn (): array => [
            'attribute_values' => [],
            'combination_hash' => Variant::hashCombination([]),
            'is_default' => true,
        ]);
    }

    /** @param array<string, string> $values */
    public function combination(array $values): static
    {
        return $this->state(fn (): array => [
            'attribute_values' => $values,
            'combination_hash' => Variant::hashCombination($values),
        ]);
    }
}
