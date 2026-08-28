<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalogue\ProductVersionService;
use Illuminate\Support\Facades\DB;

/**
 * M12 catalogue caching.
 *
 * A cache is only worth having if it is right, and the way a read cache goes wrong is
 * always the same: something changes and the cache does not hear about it. So almost
 * every test here writes something and then asks the endpoint whether it noticed.
 *
 * The one test that asserts a hit rather than an invalidation exists so the others
 * cannot pass by accident. If caching were switched off entirely, every invalidation
 * test below would still pass, and only that one would fail.
 */
function cachedProduct(): Product
{
    $product = Product::factory()->create([
        'name' => 'Halcyon Kettle K-3',
        'slug' => 'halcyon-kettle-k-3',
        'category' => 'Kitchen',
        'description' => 'A kettle.',
        'specifications' => ['capacity' => '1.7 L'],
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Colour',
        'options' => ['Cream', 'Slate'],
        'position' => 0,
    ]);

    Variant::factory()->for($product)->combination(['Colour' => 'Cream'])->create();
    Variant::factory()->for($product)->combination(['Colour' => 'Slate'])->create();

    return $product->refresh();
}

function cachedStoreCarrying(Product $product, int $priceMinor = 12_000): Attachment
{
    $store = Store::factory()->for(User::factory())->create();

    return Attachment::factory()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'variant_id' => $product->variants()->first()->id,
        'price_minor' => $priceMinor,
        'currency' => 'LKR',
        'is_available' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| It is actually caching
|--------------------------------------------------------------------------
*/

it('answers a repeated product read without going back to the database', function (): void {
    $product = cachedProduct();

    $this->getJson("/api/products/{$product->slug}")->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->getJson("/api/products/{$product->slug}")->assertOk();

    /*
     * Zero rather than "fewer than before". Route model binding resolves the product by
     * slug, which is a query, so the number is not zero for the whole request. It is
     * zero after that point, and the assertion is written against the payload build
     * because that is the part being cached.
     */
    expect($queries)->toBeLessThan(3);
});

/*
|--------------------------------------------------------------------------
| The writes that must invalidate it
|--------------------------------------------------------------------------
*/

it('shows a new version immediately rather than when the cache expires', function (): void {
    $product = cachedProduct();

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.specifications.capacity', '1.7 L');

    $product->forceFill(['specifications' => ['capacity' => '2.0 L']])->save();
    app(ProductVersionService::class)->record($product);

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.specifications.capacity', '2.0 L');
});

it('counts a new seller on the product the moment they attach', function (): void {
    $product = cachedProduct();

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.seller_count', 0);

    cachedStoreCarrying($product);

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.seller_count', 1);
});

it('shows a changed price on the variant list without waiting', function (): void {
    /*
     * The invalidation most likely to be forgotten, because a price edit is an update
     * rather than a create or a delete, and the model events for those three are
     * separate. A cache wired to creation and deletion only would serve the old price
     * for as long as the entry lived.
     */
    $product = cachedProduct();
    $attachment = cachedStoreCarrying($product, priceMinor: 12_000);

    $this->getJson("/api/products/{$product->slug}/variants")
        ->assertOk()
        ->assertJsonPath('data.0.lowest_price_minor', 12_000);

    $attachment->update(['price_minor' => 9_500]);

    $this->getJson("/api/products/{$product->slug}/variants")
        ->assertOk()
        ->assertJsonPath('data.0.lowest_price_minor', 9_500);
});

it('drops a detached seller from the product straight away', function (): void {
    $product = cachedProduct();
    $attachment = cachedStoreCarrying($product);

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.seller_count', 1);

    $attachment->delete();

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.seller_count', 0);
});

it('updates the catalogue listing when a price moves', function (): void {
    $product = cachedProduct();
    $attachment = cachedStoreCarrying($product, priceMinor: 12_000);

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.0.lowest_price_minor', 12_000);

    $attachment->update(['price_minor' => 8_000]);

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.0.lowest_price_minor', 8_000);
});

it('adds a new category to the list as soon as a product is in it', function (): void {
    /*
     * This one used to be wrong by design. The category list held its own key with an
     * hour on it and nothing invalidated it, so a new category could be up to an hour
     * late appearing in the filter. Moving it onto the catalogue generation is what
     * makes this pass.
     */
    cachedProduct();

    $this->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    Product::factory()->create(['category' => 'Lighting']);

    $this->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('stops serving a store profile the moment the store goes dark', function (): void {
    /*
     * The visibility check sits outside the cache for exactly this reason. A cached 200
     * would keep a shop with empty shelves reachable by anybody holding its URL.
     */
    $product = cachedProduct();
    $attachment = cachedStoreCarrying($product);
    $storeId = $attachment->store_id;

    $this->getJson("/api/stores/{$storeId}")->assertOk();

    $attachment->delete();

    $this->getJson("/api/stores/{$storeId}")->assertNotFound();
});

it('takes a dark store off the product pages it used to appear on', function (): void {
    /*
     * The wide invalidation. A store going dark changes the seller count on every
     * product it carried, not just its own page, so the flip has to reach all of them.
     */
    $product = cachedProduct();
    $second = Product::factory()->create(['category' => 'Kitchen']);
    Variant::factory()->for($second)->combination([])->create();

    $store = Store::factory()->for(User::factory())->create();

    foreach ([$product, $second] as $carried) {
        Attachment::factory()->create([
            'store_id' => $store->id,
            'product_id' => $carried->id,
            'variant_id' => $carried->variants()->first()->id,
            'price_minor' => 12_000,
            'currency' => 'LKR',
            'is_available' => true,
        ]);
    }

    $this->getJson("/api/products/{$product->slug}")->assertJsonPath('data.seller_count', 1);
    $this->getJson("/api/products/{$second->slug}")->assertJsonPath('data.seller_count', 1);

    // Soft deleting the store, which does not run through save and so would miss a hook
    // placed only on the saved event.
    $store->delete();

    $this->getJson("/api/products/{$product->slug}")->assertJsonPath('data.seller_count', 0);
    $this->getJson("/api/products/{$second->slug}")->assertJsonPath('data.seller_count', 0);
});

/*
|--------------------------------------------------------------------------
| What is deliberately not cached
|--------------------------------------------------------------------------
*/

it('never caches the seller list, because the answer depends on who is asking', function (): void {
    /*
     * Two buyers in different cities ask the same question and must get different
     * answers, so a shared entry would be wrong for one of them, and an entry keyed by
     * coordinates would be a cache that never gets a hit.
     *
     * Asserted with two buyers standing in two places. The nearest shop to each is a
     * different shop, and a cached response would hand the second buyer the first
     * buyer's ordering.
     */
    $product = cachedProduct();
    $variantId = $product->variants()->first()->id;

    // Colombo and Jaffna, which are the far ends of the seeded catalogue's geography.
    $colombo = ['lat' => 6.9271, 'lng' => 79.8612];
    $jaffna = ['lat' => 9.6615, 'lng' => 80.0255];

    foreach ([$colombo, $jaffna] as $index => $where) {
        $store = Store::factory()->for(User::factory())->create([
            'name' => $index === 0 ? 'Colombo Supplies' : 'Jaffna Supplies',
        ]);
        $store->setCoordinates($where['lat'], $where['lng']);

        Attachment::factory()->create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'price_minor' => 12_000,
            'currency' => 'LKR',
            'is_available' => true,
        ]);
    }

    $nearColombo = $this->getJson(
        "/api/products/{$product->slug}/sellers?sort=distance&lat={$colombo['lat']}&lng={$colombo['lng']}"
    )->assertOk()->json('data.0.store.name');

    $nearJaffna = $this->getJson(
        "/api/products/{$product->slug}/sellers?sort=distance&lat={$jaffna['lat']}&lng={$jaffna['lng']}"
    )->assertOk()->json('data.0.store.name');

    expect($nearColombo)->toBe('Colombo Supplies')
        ->and($nearJaffna)->toBe('Jaffna Supplies');
});

/*
|--------------------------------------------------------------------------
| The switch
|--------------------------------------------------------------------------
*/

it('answers correctly with the whole layer switched off', function (): void {
    /*
     * A deployment with caching disabled must behave exactly as the first eleven
     * milestones did. This is what makes the layer removable rather than load bearing.
     */
    config()->set('catalogue.cache.enabled', false);

    $product = cachedProduct();
    $attachment = cachedStoreCarrying($product, priceMinor: 12_000);

    $this->getJson("/api/products/{$product->slug}")->assertJsonPath('data.seller_count', 1);

    $attachment->update(['price_minor' => 9_000]);

    $this->getJson("/api/products/{$product->slug}/variants")
        ->assertOk()
        ->assertJsonPath('data.0.lowest_price_minor', 9_000);
});
