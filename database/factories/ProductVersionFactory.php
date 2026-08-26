<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVersion>
 */
class ProductVersionFactory extends Factory
{
    protected $model = ProductVersion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'version_number' => 1,
            'snapshot' => [],
            'is_admin_originated' => false,
        ];
    }
}
