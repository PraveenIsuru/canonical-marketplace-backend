<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
{
    protected $model = ProductAttribute::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => 'Colour',
            'options' => ['Black', 'White'],
            'position' => 0,
        ];
    }

    /** @param array<int, string> $options */
    public function named(string $name, array $options, int $position = 0): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'options' => $options,
            'position' => $position,
        ]);
    }
}
