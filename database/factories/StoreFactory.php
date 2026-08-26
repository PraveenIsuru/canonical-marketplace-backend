<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    /** Real Sri Lankan cities, so distance ordering in tests has meaningful spread. */
    public const CITIES = [
        'Colombo' => [6.9271, 79.8612],
        'Kandy' => [7.2906, 80.6337],
        'Galle' => [6.0535, 80.2210],
        'Jaffna' => [9.6615, 80.0255],
        'Negombo' => [7.2083, 79.8358],
        'Matara' => [5.9549, 80.5550],
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $city = $this->faker->randomElement(array_keys(self::CITIES));
        [$lat, $lng] = self::CITIES[$city];

        return [
            'user_id' => User::factory(),
            'name' => $this->faker->company(),
            'category' => $this->faker->randomElement(['Electronics', 'Home', 'Mobile', 'Computing']),
            'contact_email' => $this->faker->companyEmail(),
            'contact_phone' => '+9411'.$this->faker->numerify('#######'),
            'address_line' => $this->faker->streetAddress(),
            'city' => $city,
            // Jitter within roughly a city, so two stores in one city are not identical points.
            'latitude' => $lat + $this->faker->randomFloat(4, -0.05, 0.05),
            'longitude' => $lng + $this->faker->randomFloat(4, -0.05, 0.05),
            'geocode_source' => 'locationiq',
            'rating' => $this->faker->randomFloat(2, 3.0, 5.0),
            'is_live' => false,
        ];
    }

    /**
     * Pins the store to a named city.
     *
     * The offset exists so two stores in one city are not at the same point. Without
     * it the seller list shows several rows at 0.0 km, which reads as broken rather
     * than as "both are in Colombo". It is a fixed offset rather than a random one so
     * tests asserting distance ordering stay deterministic.
     */
    public function inCity(string $city, float $offsetKm = 0.0): static
    {
        [$lat, $lng] = self::CITIES[$city];

        // Roughly 111 km per degree of latitude. Close enough for seeded spread.
        $offsetDegrees = $offsetKm / 111.0;

        return $this->state(fn (): array => [
            'city' => $city,
            'latitude' => $lat + $offsetDegrees,
            'longitude' => $lng + $offsetDegrees,
        ]);
    }

    public function manuallyPinned(): static
    {
        return $this->state(fn (): array => ['geocode_source' => 'manual_pin']);
    }
}
