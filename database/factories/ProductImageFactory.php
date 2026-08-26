<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'storage_path' => 'products/'.$this->faker->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => $this->faker->numberBetween(50_000, 2_000_000),
            'position' => 0,
        ];
    }
}
