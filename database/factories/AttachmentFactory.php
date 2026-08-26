<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Store;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $variant = Variant::factory();

        return [
            'store_id' => Store::factory(),
            'variant_id' => $variant,
            'product_id' => fn (array $attributes): ?int => Variant::query()->find((int) $attributes['variant_id'])?->product_id,
            // Minor units. 250000 is 2500.00, not 250000.00.
            'price_minor' => $this->faker->numberBetween(50_000, 900_000),
            'currency' => 'LKR',
            'is_available' => true,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => ['is_available' => false]);
    }

    public function priceMinor(int $priceMinor): static
    {
        return $this->state(fn (): array => ['price_minor' => $priceMinor]);
    }
}
