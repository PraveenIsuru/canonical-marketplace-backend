<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CommunitySummary;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunitySummary>
 */
class CommunitySummaryFactory extends Factory
{
    protected $model = CommunitySummary::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'summary_text' => $this->faker->paragraph(),
            'post_count_at_generation' => $this->faker->numberBetween(3, 40),
            'generated_at' => now(),
        ];
    }
}
