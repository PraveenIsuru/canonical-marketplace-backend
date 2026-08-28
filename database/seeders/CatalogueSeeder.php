<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Attachment;
use App\Models\CommunitySummary;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\ProductView;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalogue\ProductVersionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The catalogue the frontend builds against.
 *
 * There is no mock API in this project, so this seeder is the only thing standing
 * between the screens and an empty database. That makes the awkward states as
 * important as the happy path: a product nobody sells, a store that is dark, a variant
 * nobody carries, a seller who is out of stock. Every one of those has a screen state
 * that has to be built and cannot be built against data that never produces it.
 *
 * Stores are spread across real cities so distance ordering is visible rather than
 * theoretical.
 */
class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $stores = $this->createStores();

        $this->createPhoneWithTwoAttributes($stores);
        $this->createLaptopWithOneAttribute($stores);
        $this->createSimpleProductWithNoAttributes($stores);
        $this->createProductWithNoSellers();
        $this->createProductCarriedOnlyByADarkStore();

        $this->createVersionHistory($stores);
        $this->createViewHistory($stores);

        $this->command->info('Catalogue seeded.');
    }

    /**
     * Six stores in five cities.
     *
     * Two share a city, so "nearest" is not trivially the only store in town, and the
     * distance ordering actually has to work.
     *
     * @return array<string, Store>
     */
    private function createStores(): array
    {
        $make = function (string $key, string $name, string $city, float $offsetKm = 0.0): Store {
            $user = User::factory()->create([
                'name' => $name.' Owner',
                'email' => Str::slug($key).'@example.com',
            ]);

            return Store::factory()
                ->for($user)
                ->inCity($city, $offsetKm)
                ->create(['name' => $name, 'city' => $city]);
        };

        return [
            // The two Colombo stores are a few kilometres apart rather than on the same
            // point, so the distance column shows a real difference between them.
            'colombo_a' => $make('colombo-a', 'Fort Electronics', 'Colombo', 1.5),
            'colombo_b' => $make('colombo-b', 'Pettah Gadgets', 'Colombo', 4.0),
            'kandy' => $make('kandy', 'Hill Country Tech', 'Kandy', 2.0),
            'galle' => $make('galle', 'Southern Devices', 'Galle'),
            'jaffna' => $make('jaffna', 'Northern Supplies', 'Jaffna'),
            // Deliberately holds no attachments, so it stays dark and must never appear
            // in a seller list or be reachable at its own public URL.
            'dark' => $make('dark', 'Not Yet Trading', 'Matara'),
        ];
    }

    /**
     * The flagship case: two attributes, so the cross product is six combinations.
     *
     * One combination is deliberately carried by nobody, because the product page has
     * to render it as having no sellers rather than hiding it.
     *
     * @param  array<string, Store>  $stores
     */
    private function createPhoneWithTwoAttributes(array $stores): void
    {
        $product = Product::factory()->create([
            'name' => 'Vertex One Smartphone',
            'slug' => 'vertex-one-smartphone',
            'description' => 'A mid range smartphone with a 6.1 inch display and a dual camera.',
            'category' => 'Mobile',
            'specifications' => [
                'Display' => '6.1 inch OLED',
                'Battery' => '4500 mAh',
                'Weight' => '187 g',
            ],
        ]);

        ProductAttribute::factory()->for($product)->named('Colour', ['Black', 'White', 'Blue'], 0)->create();
        ProductAttribute::factory()->for($product)->named('Capacity', ['128GB', '256GB'], 1)->create();

        $variants = $this->generateVariants($product, [
            'Colour' => ['Black', 'White', 'Blue'],
            'Capacity' => ['128GB', '256GB'],
        ]);

        ProductImage::factory()->for($product)->count(3)->sequence(
            ['position' => 0],
            ['position' => 1],
            ['position' => 2],
        )->create();

        $key = fn (string $colour, string $capacity): string => $colour.'|'.$capacity;

        // Black 128GB is the common one, carried widely at differing prices so the
        // price sort and the distance sort disagree, which is what makes the sort
        // control worth testing.
        $this->attach($stores['colombo_a'], $variants[$key('Black', '128GB')], 249_900);
        $this->attach($stores['colombo_b'], $variants[$key('Black', '128GB')], 244_500);
        $this->attach($stores['kandy'], $variants[$key('Black', '128GB')], 239_000);
        $this->attach($stores['galle'], $variants[$key('Black', '128GB')], 255_000);
        $this->attach($stores['jaffna'], $variants[$key('Black', '128GB')], 235_000);

        $this->attach($stores['colombo_a'], $variants[$key('Black', '256GB')], 289_900);
        $this->attach($stores['kandy'], $variants[$key('Black', '256GB')], 284_000);

        $this->attach($stores['colombo_b'], $variants[$key('White', '128GB')], 249_900);

        // Out of stock rather than absent. The row stays, the store stays live, and the
        // seller drops out of the availability filter only.
        $this->attach($stores['galle'], $variants[$key('White', '256GB')], 291_000, available: false);

        $this->attach($stores['jaffna'], $variants[$key('Blue', '128GB')], 251_000);

        // Blue 256GB is carried by nobody. It must still render, labelled as having no
        // sellers yet, because generated combinations are permanent.

        CommunitySummary::factory()->for($product)->create([
            'summary_text' => 'Owners consistently praise the battery life and the screen. '
                .'The most common complaint is that the camera struggles in low light.',
            'post_count_at_generation' => 14,
        ]);
    }

    /**
     * One attribute, three combinations, carried by two stores in the same city.
     *
     * @param  array<string, Store>  $stores
     */
    private function createLaptopWithOneAttribute(array $stores): void
    {
        $product = Product::factory()->create([
            'name' => 'Meridian 14 Laptop',
            'slug' => 'meridian-14-laptop',
            'description' => 'A 14 inch ultraportable laptop aimed at students.',
            'category' => 'Computing',
            'specifications' => [
                'Display' => '14 inch IPS',
                'Processor' => 'Octa core',
                'Weight' => '1.3 kg',
            ],
        ]);

        ProductAttribute::factory()->for($product)->named('Memory', ['8GB', '16GB', '32GB'], 0)->create();

        $variants = $this->generateVariants($product, ['Memory' => ['8GB', '16GB', '32GB']]);

        ProductImage::factory()->for($product)->count(2)->sequence(
            ['position' => 0],
            ['position' => 1],
        )->create();

        $this->attach($stores['colombo_a'], $variants['8GB'], 425_000);
        $this->attach($stores['colombo_b'], $variants['8GB'], 419_000);
        $this->attach($stores['kandy'], $variants['16GB'], 519_000);

        // 32GB carried by nobody.
    }

    /**
     * A product with no attributes at all, which gets exactly one default variant.
     *
     * The product page renders no variant selector for this one.
     *
     * @param  array<string, Store>  $stores
     */
    private function createSimpleProductWithNoAttributes(array $stores): void
    {
        $product = Product::factory()->create([
            'name' => 'Standard USB-C Cable 2m',
            'slug' => 'standard-usb-c-cable-2m',
            'description' => 'A two metre USB-C charging and data cable.',
            'category' => 'Home',
            'specifications' => ['Length' => '2 m', 'Rating' => '60 W'],
        ]);

        $variant = Variant::factory()->for($product)->default()->create();

        $this->attach($stores['colombo_a'], $variant, 2_500);
        $this->attach($stores['galle'], $variant, 2_900);
        $this->attach($stores['kandy'], $variant, 2_750);
    }

    /**
     * A product no store carries.
     *
     * It must still appear in the catalogue with no price and a seller count of zero,
     * and its page must still load. This is the state that gets forgotten.
     */
    private function createProductWithNoSellers(): void
    {
        $product = Product::factory()->create([
            'name' => 'Orbit Wireless Earbuds',
            'slug' => 'orbit-wireless-earbuds',
            'description' => 'In ear wireless earbuds with active noise cancellation.',
            'category' => 'Audio',
            'specifications' => ['Battery' => '6 hours', 'Charging' => 'USB-C'],
        ]);

        ProductAttribute::factory()->for($product)->named('Colour', ['Black', 'Beige'], 0)->create();

        $this->generateVariants($product, ['Colour' => ['Black', 'Beige']]);
        ProductImage::factory()->for($product)->create(['position' => 0]);
    }

    /**
     * A product whose only would be seller is dark.
     *
     * The dark store holds no attachments, so the product has no sellers at all. This
     * separates "no sellers" from "sellers exist but are hidden", which are different
     * bugs when the seller list query is wrong.
     */
    private function createProductCarriedOnlyByADarkStore(): void
    {
        $product = Product::factory()->create([
            'name' => 'Lumen Desk Lamp',
            'slug' => 'lumen-desk-lamp',
            'description' => 'An adjustable LED desk lamp with three colour temperatures.',
            'category' => 'Home',
            'specifications' => ['Power' => '9 W'],
        ]);

        Variant::factory()->for($product)->default()->create();
    }

    /**
     * Version chains for the seeded catalogue (M10, EP-46 and EP-47).
     *
     * Without these, every seeded product has an empty history and the version screens
     * have nothing to render but their empty state. The phone gets a three step chain
     * on purpose, so all three cases a history can show are reachable: a version 1 that
     * created the record and therefore changed nothing, a version caused by a seller's
     * accepted proposal, and an administrator edit that names no administrator.
     *
     * The chain is written by walking the product backwards through the states it would
     * have passed through and then restoring it, so the newest version matches the
     * product as the rest of the seeder left it. A history whose latest snapshot
     * disagreed with the live record would be worse than no history at all.
     *
     * @param  array<string, Store>  $stores
     */
    private function createVersionHistory(array $stores): void
    {
        $versions = app(ProductVersionService::class);
        $administrator = User::query()->where('is_admin', true)->first();

        $phone = Product::query()->where('slug', 'vertex-one-smartphone')->firstOrFail();
        $asSeeded = $phone->specifications;

        // Version 1, as the wizard would have written it when the record was created.
        $phone->forceFill(['specifications' => [
            'Display' => '6.1 inch OLED',
            'Battery' => '4000 mAh',
        ]])->save();

        $versions->record(
            $phone->refresh(),
            causedByStore: $stores['colombo_a'],
            causedByUser: $stores['colombo_a']->user,
        );

        // Version 2, a peer reviewed correction to the battery figure.
        $phone->forceFill(['specifications' => [
            'Display' => '6.1 inch OLED',
            'Battery' => '4500 mAh',
        ]])->save();

        $versions->record(
            $phone->refresh(),
            causedByStore: $stores['colombo_b'],
            causedByUser: $stores['colombo_b']->user,
        );

        // Version 3, an administrator adding the weight directly. No store caused it,
        // and no administrator is named in the response.
        $phone->forceFill(['specifications' => $asSeeded])->save();

        $versions->record(
            $phone->refresh(),
            causedByUser: $administrator,
            isAdminOriginated: true,
        );

        /*
         * Everything else gets a single version 1, including the products nobody
         * carries. A product with no sellers still has a canonical record and still has
         * a history, and its version 1 has no causing store at all, which is a state the
         * screen has to render.
         */
        $firstSeller = [
            'meridian-14-laptop' => $stores['colombo_a'],
            'standard-usb-c-cable-2m' => $stores['galle'],
            'orbit-wireless-earbuds' => null,
            'lumen-desk-lamp' => null,
        ];

        foreach ($firstSeller as $slug => $store) {
            $product = Product::query()->where('slug', $slug)->firstOrFail();

            $versions->record($product, causedByStore: $store, causedByUser: $store?->user);
        }
    }

    /**
     * Six weeks of product page views (M10, EP-39 and EP-52).
     *
     * Deterministic rather than random, so two people looking at the same seeded
     * analytics see the same chart and can talk about it. The shape has a weekend dip
     * in it because a flat line tells nobody whether the date range control works.
     *
     * Deliberately uneven across stores. Northern Supplies receives none at all, so the
     * state where a seller has listings, real interest in the products they stock, and
     * nothing attributed to them is reachable on screen rather than only in a test.
     *
     * @param  array<string, Store>  $stores
     */
    private function createViewHistory(array $stores): void
    {
        $attributable = [
            'vertex-one-smartphone' => [$stores['colombo_a'], $stores['colombo_b'], $stores['kandy']],
            'meridian-14-laptop' => [$stores['colombo_a'], $stores['colombo_b']],
            'standard-usb-c-cable-2m' => [$stores['galle'], $stores['kandy']],
            // Nobody carries these two, so every view of them is unattributed. They
            // still count at product level, which is the difference the analytics screen
            // has to make visible.
            'orbit-wireless-earbuds' => [],
            'lumen-desk-lamp' => [],
        ];

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $rows = [];

        foreach ($attributable as $slug => $carriers) {
            $product = Product::query()->where('slug', $slug)->firstOrFail();

            for ($daysAgo = 0; $daysAgo < 45; $daysAgo++) {
                $day = $today->subDays($daysAgo);

                // A quieter weekend, and a slow decline going back in time, so the
                // default thirty day window and a wider one look different.
                $base = $day->isWeekend() ? 2 : 5;
                $views = max(1, $base - intdiv($daysAgo, 15));

                for ($n = 0; $n < $views; $n++) {
                    $carrier = $carriers === [] ? null : ($carriers[($daysAgo + $n) % (count($carriers) + 1)] ?? null);

                    $rows[] = [
                        'product_id' => $product->id,
                        // The null slot in the rotation is what makes a share of every
                        // product's views unattributed, which is what most real traffic
                        // is: somebody arriving at the product directly.
                        'store_id' => $carrier?->id,
                        'user_id' => null,
                        'viewed_at' => $day->addHours(9 + $n),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ProductView::insert($chunk);
        }

        $this->command->info(count($rows).' product views seeded across 45 days.');
    }

    /**
     * Generates the full cross product of attribute options.
     *
     * A simplified version of what the wizard will do at M5. Kept here rather than
     * shared, because the real one belongs in a service with its own tests and this
     * only has to be right for seeded data.
     *
     * @param  array<string, array<int, string>>  $attributes
     * @return array<string, Variant> keyed by option values joined with a pipe
     */
    private function generateVariants(Product $product, array $attributes): array
    {
        $combinations = [[]];

        foreach ($attributes as $name => $options) {
            $expanded = [];

            foreach ($combinations as $combination) {
                foreach ($options as $option) {
                    $expanded[] = $combination + [$name => $option];
                }
            }

            $combinations = $expanded;
        }

        $variants = [];

        foreach ($combinations as $combination) {
            $variant = Variant::factory()->for($product)->combination($combination)->create();
            $variants[implode('|', array_values($combination))] = $variant;
        }

        return $variants;
    }

    /**
     * Creates an attachment and lets the model recompute the store's live flag.
     *
     * Deliberately not setting is_live by hand. Going through the same path the
     * application uses means the seeded data cannot disagree with what the application
     * would have produced.
     */
    private function attach(Store $store, Variant $variant, int $priceMinor, bool $available = true): void
    {
        Attachment::factory()->create([
            'store_id' => $store->id,
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'price_minor' => $priceMinor,
            'is_available' => $available,
        ]);
    }
}
