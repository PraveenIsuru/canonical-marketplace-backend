<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\CommunitySummary;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;

/**
 * M2 Catalogue read path. EP-08 to EP-13 and EP-53.
 *
 * The tests the build plan names for this milestone. Distance ordering is asserted
 * against known coordinates rather than against whatever the query happens to return,
 * because an ordering bug here is invisible until a buyer drives to the wrong shop.
 */

/** Creates a live store in a named city, with an attachment so the live flag turns on. */
function liveStoreCarrying(Variant $variant, string $city, int $priceMinor, bool $available = true): Store
{
    $store = Store::factory()->for(User::factory())->inCity($city)->create();

    Attachment::factory()->create([
        'store_id' => $store->id,
        'variant_id' => $variant->id,
        'product_id' => $variant->product_id,
        'price_minor' => $priceMinor,
        'is_available' => $available,
    ]);

    return $store->fresh();
}

/*
|--------------------------------------------------------------------------
| EP-08 Catalogue listing
|--------------------------------------------------------------------------
*/

it('returns a product with zero sellers, with a null price', function (): void {
    $product = Product::factory()->create();
    Variant::factory()->for($product)->default()->create();

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.0.slug', $product->slug)
        // Null, never zero. Zero would render as free.
        ->assertJsonPath('data.0.lowest_price_minor', null)
        ->assertJsonPath('data.0.seller_count', 0);
});

it('counts a store once even when it carries several variants of one product', function (): void {
    $product = Product::factory()->create();
    $a = Variant::factory()->for($product)->combination(['Size' => 'S'])->create();
    $b = Variant::factory()->for($product)->combination(['Size' => 'M'])->create();

    $store = liveStoreCarrying($a, 'Colombo', 1000);
    Attachment::factory()->create([
        'store_id' => $store->id,
        'variant_id' => $b->id,
        'product_id' => $product->id,
        'price_minor' => 2000,
    ]);

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.0.seller_count', 1)
        ->assertJsonPath('data.0.lowest_price_minor', 1000);
});

it('excludes dark stores from the price and the seller count', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    // A store with no attachment stays dark, so its would be price must not surface.
    Store::factory()->for(User::factory())->create();
    liveStoreCarrying($variant, 'Colombo', 5000);

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.0.seller_count', 1)
        ->assertJsonPath('data.0.lowest_price_minor', 5000);
});

it('filters the catalogue by category', function (): void {
    Product::factory()->create(['category' => 'Mobile']);
    Product::factory()->create(['category' => 'Audio']);

    $this->getJson('/api/products?category=Mobile')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'Mobile');
});

it('caps per_page so a caller cannot request the whole catalogue at once', function (): void {
    Product::factory()->count(3)->create();

    $this->getJson('/api/products?per_page=5000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

/*
|--------------------------------------------------------------------------
| EP-09 and EP-10 Product and variants
|--------------------------------------------------------------------------
*/

it('returns the canonical record by slug', function (): void {
    $product = Product::factory()->create(['slug' => 'known-slug', 'name' => 'Known Product']);
    ProductAttribute::factory()->for($product)->named('Colour', ['Black'], 0)->create();

    $this->getJson('/api/products/known-slug')
        ->assertOk()
        ->assertJsonPath('data.name', 'Known Product')
        ->assertJsonPath('data.attributes.0.name', 'Colour');
});

it('never exposes the creating store on a product', function (): void {
    $store = Store::factory()->for(User::factory())->create();
    $product = Product::factory()->create(['created_by_store_id' => $store->id]);

    $body = $this->getJson("/api/products/{$product->slug}")->getContent();

    // Historical attribution only. Exposing it would imply a seller owns the record.
    expect($body)->not->toContain('created_by_store_id');
});

it('returns 404 for an unknown slug', function (): void {
    $this->getJson('/api/products/no-such-product')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('returns every generated combination including those nobody carries', function (): void {
    $product = Product::factory()->create();
    $carried = Variant::factory()->for($product)->combination(['Colour' => 'Black'])->create();
    Variant::factory()->for($product)->combination(['Colour' => 'Blue'])->create();

    liveStoreCarrying($carried, 'Colombo', 1000);

    $response = $this->getJson("/api/products/{$product->slug}/variants")->assertOk();

    // Both, not just the carried one. Omitting the other would silently reintroduce
    // variant removal, which nothing in the system permits.
    $response->assertJsonCount(2, 'data');

    $blue = collect($response->json('data'))->firstWhere('attribute_values.Colour', 'Blue');
    expect($blue['seller_count'])->toBe(0)
        ->and($blue['lowest_price_minor'])->toBeNull();
});

it('returns exactly one default variant for a product with no attributes', function (): void {
    $product = Product::factory()->create();
    Variant::factory()->for($product)->default()->create();

    $this->getJson("/api/products/{$product->slug}/variants")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_default', true);
});

/*
|--------------------------------------------------------------------------
| EP-11 Seller list
|--------------------------------------------------------------------------
*/

it('orders sellers by distance against known coordinates', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    // Deliberately priced so that price order and distance order disagree. If the
    // query silently fell back to price, this test would still see Jaffna first.
    liveStoreCarrying($variant, 'Jaffna', 100);
    liveStoreCarrying($variant, 'Kandy', 200);
    liveStoreCarrying($variant, 'Colombo', 300);

    $cities = collect(
        $this->getJson('/api/products/'.$product->slug.'/sellers?lat=6.9271&lng=79.8612')
            ->assertOk()
            ->json('data')
    )->pluck('store.city')->all();

    expect($cities)->toBe(['Colombo', 'Kandy', 'Jaffna']);
});

it('reorders when the buyer is somewhere else', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    liveStoreCarrying($variant, 'Colombo', 300);
    liveStoreCarrying($variant, 'Jaffna', 100);

    $cities = collect(
        $this->getJson('/api/products/'.$product->slug.'/sellers?lat=9.6615&lng=80.0255')
            ->json('data')
    )->pluck('store.city')->all();

    expect($cities)->toBe(['Jaffna', 'Colombo']);
});

it('returns a null distance and price ordering when no coordinates are supplied', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    liveStoreCarrying($variant, 'Colombo', 900);
    liveStoreCarrying($variant, 'Kandy', 100);

    $response = $this->getJson("/api/products/{$product->slug}/sellers")->assertOk();

    // Null rather than zero. Zero would read as "at your doorstep".
    $response->assertJsonPath('data.0.distance_km', null);

    expect(collect($response->json('data'))->pluck('price_minor')->all())->toBe([100, 900]);
});

it('excludes dark stores from the seller list', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    $live = liveStoreCarrying($variant, 'Colombo', 500);

    /*
     * Attach then detach, so the store held stock once and is now dark. This is the
     * case a naive "is_live defaults false" check would miss.
     *
     * Deleted one model at a time on purpose. A mass delete through the query builder,
     * $dark->attachments()->delete(), does not fire model events, so the live flag
     * would silently stay true and the dark store would keep appearing in seller
     * lists. That is the flag drift the design accepts and mitigates with a periodic
     * reconciliation job, and it is worth knowing the hook has this hole.
     */
    $dark = liveStoreCarrying($variant, 'Kandy', 400);
    $dark->attachments->each->delete();

    expect($dark->fresh()->is_live)->toBeFalse();

    $response = $this->getJson("/api/products/{$product->slug}/sellers")->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.store.id'))->toBe($live->id);
});

it('filters the seller list by variant', function (): void {
    $product = Product::factory()->create();
    $black = Variant::factory()->for($product)->combination(['Colour' => 'Black'])->create();
    $blue = Variant::factory()->for($product)->combination(['Colour' => 'Blue'])->create();

    liveStoreCarrying($black, 'Colombo', 100);
    liveStoreCarrying($blue, 'Kandy', 200);

    $this->getJson("/api/products/{$product->slug}/sellers?variant_id={$black->id}")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.variant_id', $black->id);
});

it('filters the seller list by price, rating, availability, and distance', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    // Every rating is set explicitly. The factory assigns a random one between 3 and
    // 5, which would make the min_rating assertion below pass or fail by luck.
    $cheap = liveStoreCarrying($variant, 'Colombo', 100);
    $cheap->forceFill(['rating' => 4.8])->save();

    $dear = liveStoreCarrying($variant, 'Colombo', 900);
    $dear->forceFill(['rating' => 2.0])->save();

    $far = liveStoreCarrying($variant, 'Jaffna', 150);
    $far->forceFill(['rating' => 3.1])->save();

    $unavailable = liveStoreCarrying($variant, 'Kandy', 120, available: false);
    $unavailable->forceFill(['rating' => 3.2])->save();

    $this->getJson("/api/products/{$product->slug}/sellers?max_price_minor=200")
        ->assertJsonPath('meta.total', 3);

    $this->getJson("/api/products/{$product->slug}/sellers?min_rating=4.0")
        ->assertJsonPath('meta.total', 1);

    $this->getJson("/api/products/{$product->slug}/sellers?available_only=1")
        ->assertJsonPath('meta.total', 3);

    $this->getJson("/api/products/{$product->slug}/sellers?lat=6.9271&lng=79.8612&max_distance_km=50")
        ->assertJsonPath('meta.total', 2);

    expect([$far->is_live, $unavailable->is_live])->toBe([true, true]);
});

it('ignores a distance filter when no coordinates were supplied', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    liveStoreCarrying($variant, 'Jaffna', 100);

    // Meaningless rather than exclusionary. A buyer who has not shared a location
    // should still see sellers rather than an empty list.
    $this->getJson("/api/products/{$product->slug}/sellers?max_distance_km=1")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('returns contact details to an anonymous caller', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();
    $store = liveStoreCarrying($variant, 'Colombo', 100);

    // The whole purpose of the endpoint. The platform works on contact and redirect.
    $this->getJson("/api/products/{$product->slug}/sellers")
        ->assertOk()
        ->assertJsonPath('data.0.store.contact_email', $store->contact_email)
        ->assertJsonPath('data.0.store.address_line', $store->address_line);
});

it('does not change the seller list when a token happens to be present', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();
    liveStoreCarrying($variant, 'Colombo', 100);

    $anonymous = $this->getJson("/api/products/{$product->slug}/sellers")->json('data');

    $authenticated = $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/products/{$product->slug}/sellers")
        ->json('data');

    expect($authenticated)->toBe($anonymous);
});

it('sends every price as an integer', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();
    liveStoreCarrying($variant, 'Colombo', 249_900);

    $price = $this->getJson("/api/products/{$product->slug}/sellers")->json('data.0.price_minor');

    expect($price)->toBeInt()->toBe(249_900);
});

/*
|--------------------------------------------------------------------------
| EP-12, EP-13, EP-53
|--------------------------------------------------------------------------
*/

it('returns a null summary rather than an empty string when none exists', function (): void {
    $product = Product::factory()->create();

    // An empty string would render as a blank panel, which looks broken.
    $this->getJson("/api/products/{$product->slug}/summary")
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('returns the sentiment summary where one exists', function (): void {
    $product = Product::factory()->create();
    CommunitySummary::factory()->for($product)->create(['summary_text' => 'Broadly positive.']);

    $this->getJson("/api/products/{$product->slug}/summary")
        ->assertOk()
        ->assertJsonPath('data.summary', 'Broadly positive.');
});

it('returns a live store profile with its listings', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();
    $store = liveStoreCarrying($variant, 'Colombo', 100);

    $this->getJson("/api/stores/{$store->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $store->id)
        ->assertJsonCount(1, 'data.listings');
});

it('returns 404 for a dark store rather than an empty profile', function (): void {
    $dark = Store::factory()->for(User::factory())->create();

    // Not visible to buyers, so it must not be reachable by guessing an id either.
    $this->getJson("/api/stores/{$dark->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('derives the category list with counts', function (): void {
    Product::factory()->count(2)->create(['category' => 'Mobile']);
    Product::factory()->create(['category' => 'Audio']);

    $categories = collect($this->getJson('/api/categories')->assertOk()->json('data'))
        ->pluck('product_count', 'name');

    expect($categories['Mobile'])->toBe(2)
        ->and($categories['Audio'])->toBe(1);
});

it('serialises an empty combination as an object, not an array', function (): void {
    /*
     * A product with no attributes stores its default variant as an empty JSON array.
     * Decoded naively that comes back as [], which is a different type from the object
     * every other variant produces, and a typed client rejects it.
     *
     * Both endpoints that expose a combination must agree.
     */
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();
    liveStoreCarrying($variant, 'Colombo', 2500);

    $fromVariants = $this->getJson("/api/products/{$product->slug}/variants")
        ->assertOk()
        ->json('data.0.attribute_values');

    $fromSellers = $this->getJson("/api/products/{$product->slug}/sellers")
        ->assertOk()
        ->json('data.0.attribute_values');

    expect($fromVariants)->toBe([])
        ->and($fromSellers)->toBe([]);

    // json_decode to an array cannot tell {} from [], so assert the raw JSON too.
    expect($this->getJson("/api/products/{$product->slug}/sellers")->getContent())
        ->toContain('"attribute_values":{}')
        ->not->toContain('"attribute_values":[]');
});
