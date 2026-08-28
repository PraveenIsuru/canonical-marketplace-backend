<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductView>
 */
class ProductViewFactory extends Factory
{
    protected $model = ProductView::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // Unattributed by default, which is what most real views are.
            'store_id' => null,
            'user_id' => null,
            'viewed_at' => now(),
        ];
    }

    /** Recorded on a given day, at midday UTC so a timezone slip would be visible. */
    public function on(string $date): self
    {
        return $this->state(fn (): array => ['viewed_at' => $date.' 12:00:00']);
    }
}
