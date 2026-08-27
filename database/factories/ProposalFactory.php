<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\Proposal;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    protected $model = Proposal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $opensAt = now();

        return [
            'product_id' => Product::factory(),
            'store_id' => Store::factory(),
            'changes' => ['name' => ['from' => 'Old name', 'to' => 'New name']],
            'ai_answers' => [],
            // Neither of these ever reaches a response. They are here because the
            // columns are NOT NULL and the resolution matrix reads the band at M7.
            'confidence_score' => 0.800,
            'confidence_band' => Proposal::BAND_HIGH,
            'status' => Proposal::STATUS_PENDING,
            'review_opens_at' => $opensAt,
            'review_closes_at' => $opensAt->addDays(Proposal::REVIEW_WINDOW_DAYS),
        ];
    }

    public function band(string $band, float $score): static
    {
        return $this->state(fn (): array => [
            'confidence_band' => $band,
            'confidence_score' => $score,
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /** A proposal whose three day window has already run out. */
    public function closed(): static
    {
        return $this->state(fn (): array => [
            'review_opens_at' => now()->subDays(Proposal::REVIEW_WINDOW_DAYS + 1),
            'review_closes_at' => now()->subDay(),
        ]);
    }
}
