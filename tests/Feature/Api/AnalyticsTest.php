<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use Carbon\CarbonImmutable;

/**
 * M10 View recording and seller analytics (EP-52, EP-39).
 *
 * The build plan's M10 list covers view counts being attributed to the right store.
 * That is the assertion this file exists for, and it is made from both ends: the
 * public endpoint attributing correctly, and the seller endpoint reading back only
 * what belongs to the caller.
 *
 * Helpers are prefixed and local to this file so it runs alone.
 */
function m10_product(string $name = 'Aurora Field Recorder FR-2'): Product
{
    $product = Product::factory()->create(['name' => $name, 'category' => 'Audio']);

    Variant::factory()->for($product)->combination([])->create();

    return $product->refresh();
}

function m10_store(): Store
{
    return Store::factory()->for(User::factory())->create();
}

function m10_carry(Store $store, Product $product, int $priceMinor = 450_000): Attachment
{
    return Attachment::create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'variant_id' => $product->variants()->first()->id,
        'price_minor' => $priceMinor,
        'currency' => 'LKR',
        'is_available' => true,
    ]);
}

function m10_view(Product $product, ?Store $store, string $date): ProductView
{
    return ProductView::create([
        'product_id' => $product->id,
        'store_id' => $store?->id,
        'user_id' => null,
        'viewed_at' => $date.' 12:00:00',
    ]);
}

/*
|--------------------------------------------------------------------------
| EP-52 Recording a view
|--------------------------------------------------------------------------
*/

it('records a product view with no token at all', function (): void {
    $product = m10_product();

    $this->postJson("/api/products/{$product->slug}/views")
        ->assertCreated()
        ->assertHeader('X-Access-Level', 'public')
        ->assertJsonPath('data.recorded', true)
        ->assertJsonPath('data.store_id', null);

    expect(ProductView::where('product_id', $product->id)->count())->toBe(1);
});

it('attributes a view to a store that carries the product', function (): void {
    $product = m10_product();
    $store = m10_store();
    m10_carry($store, $product);

    $this->postJson("/api/products/{$product->slug}/views", ['store_id' => $store->id])
        ->assertCreated()
        ->assertJsonPath('data.store_id', $store->id);

    expect(ProductView::where('product_id', $product->id)->first()->store_id)->toBe($store->id);
});

it('drops a store context from a store that does not carry the product', function (): void {
    $product = m10_product();
    $other = m10_store();

    /*
     * The refusal a naive implementation would make here is a 422. It would also be a
     * 422 for the ordinary race where a seller detaches between the page rendering and
     * the view arriving, which is a visible error for a visitor who did nothing wrong.
     * The view is kept and only the attribution is discarded.
     */
    $this->postJson("/api/products/{$product->slug}/views", ['store_id' => $other->id])
        ->assertCreated()
        ->assertJsonPath('data.recorded', true)
        ->assertJsonPath('data.store_id', null);

    expect(ProductView::where('product_id', $product->id)->first()->store_id)->toBeNull();
});

it('drops a store context naming a store that does not exist', function (): void {
    $product = m10_product();

    $this->postJson("/api/products/{$product->slug}/views", ['store_id' => 999_999])
        ->assertCreated()
        ->assertJsonPath('data.store_id', null);
});

it('records no user even when a token happens to be present', function (): void {
    $product = m10_product();

    // Invariant 9. A public route resolves no session, so there is nobody to record.
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson("/api/products/{$product->slug}/views")
        ->assertCreated();

    expect(ProductView::first()->user_id)->toBeNull();
});

it('refuses a malformed store context', function (): void {
    $product = m10_product();

    $this->postJson("/api/products/{$product->slug}/views", ['store_id' => 'colombo-audio'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('answers not_found for a view on a product that does not exist', function (): void {
    $this->postJson('/api/products/no-such-product/views')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

/*
|--------------------------------------------------------------------------
| EP-39 Access
|--------------------------------------------------------------------------
*/

it('refuses analytics to an anonymous caller', function (): void {
    $this->getJson('/api/stores/mine/analytics')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('refuses analytics to a user with no store', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/stores/mine/analytics')
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

/*
|--------------------------------------------------------------------------
| EP-39 Attribution
|--------------------------------------------------------------------------
*/

it('counts only the views attributed to the calling store', function (): void {
    $product = m10_product();
    $mine = m10_store();
    $theirs = m10_store();

    m10_carry($mine, $product);
    m10_carry($theirs, $product);

    m10_view($product, $mine, '2026-08-10');
    m10_view($product, $mine, '2026-08-10');
    m10_view($product, $theirs, '2026-08-10');
    // Unattributed, so it belongs to neither store but counts at product level.
    m10_view($product, null, '2026-08-10');

    $this->actingAs($mine->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertJsonPath('data.store_views', 2)
        ->assertJsonPath('data.product_views', 4)
        ->assertJsonPath('data.products.0.store_views', 2)
        ->assertJsonPath('data.products.0.product_views', 4)
        ->assertJsonPath('data.products.0.is_carried', true);

    // The other store reads its own single view from the same rows.
    $this->actingAs($theirs->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertJsonPath('data.store_views', 1)
        ->assertJsonPath('data.product_views', 4);
});

it('leaves a store with no views at zero rather than borrowing another stores', function (): void {
    $product = m10_product();
    $mine = m10_store();
    $theirs = m10_store();

    m10_carry($mine, $product);
    m10_carry($theirs, $product);

    m10_view($product, $theirs, '2026-08-10');

    $this->actingAs($mine->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertJsonPath('data.store_views', 0)
        // The product is still listed, at zero, because a carried listing missing from
        // this list reads as lost rather than as unvisited.
        ->assertJsonPath('data.products.0.id', $product->id)
        ->assertJsonPath('data.products.0.store_views', 0);
});

it('counts only views inside the requested range', function (): void {
    $product = m10_product();
    $store = m10_store();
    m10_carry($store, $product);

    m10_view($product, $store, '2026-07-31');
    m10_view($product, $store, '2026-08-01');
    m10_view($product, $store, '2026-08-31');
    m10_view($product, $store, '2026-09-01');

    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        // Both boundary days are inclusive, which is what a person means by a range.
        ->assertJsonPath('data.store_views', 2);
});

it('returns one daily entry per day in the range, including the empty ones', function (): void {
    $product = m10_product();
    $store = m10_store();
    m10_carry($store, $product);

    m10_view($product, $store, '2026-08-03');

    $response = $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-05')
        ->assertOk();

    $daily = $response->json('data.daily');

    expect($daily)->toHaveCount(5)
        ->and($daily[0])->toBe(['date' => '2026-08-01', 'store_views' => 0, 'product_views' => 0])
        ->and($daily[2])->toBe(['date' => '2026-08-03', 'store_views' => 1, 'product_views' => 1])
        ->and($daily[4]['date'])->toBe('2026-08-05');
});

it('keeps historical views for a product the seller has since detached from', function (): void {
    $product = m10_product();
    $store = m10_store();
    $listing = m10_carry($store, $product);

    m10_view($product, $store, '2026-08-10');

    $listing->delete();

    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertJsonPath('data.store_views', 1)
        // Still counted, and flagged as no longer stocked so the screen can say so.
        ->assertJsonPath('data.products.0.is_carried', false);
});

it('orders the breakdown by what reached this seller first', function (): void {
    $quiet = m10_product('Quiet Product');
    $busy = m10_product('Busy Product');
    $store = m10_store();

    m10_carry($store, $quiet);
    m10_carry($store, $busy);

    m10_view($quiet, $store, '2026-08-10');
    m10_view($busy, $store, '2026-08-10');
    m10_view($busy, $store, '2026-08-11');

    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->assertJsonPath('data.products.0.name', 'Busy Product')
        ->assertJsonPath('data.products.1.name', 'Quiet Product');
});

/*
|--------------------------------------------------------------------------
| EP-39 The range itself
|--------------------------------------------------------------------------
*/

it('defaults to the last thirty days ending today', function (): void {
    $store = m10_store();

    CarbonImmutable::setTestNow('2026-08-28T09:00:00Z');

    $response = $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics')
        ->assertOk();

    $response->assertJsonPath('data.to', '2026-08-28')
        ->assertJsonPath('data.from', '2026-07-30');

    expect($response->json('data.daily'))->toHaveCount(30);

    CarbonImmutable::setTestNow();
});

it('refuses a range that ends before it starts', function (): void {
    $store = m10_store();

    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-31&to=2026-08-01')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('refuses a date that is not a plain calendar date', function (): void {
    $store = m10_store();

    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=last-tuesday')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('pulls an over long range forward rather than refusing it', function (): void {
    $store = m10_store();

    // Asking for five years answers the most recent year of it. The seller asked for a
    // period, and a validation error about a ceiling they had no way to know is worse
    // than an answer.
    $response = $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2021-01-01&to=2026-08-28')
        ->assertOk();

    expect($response->json('data.from'))->toBe('2025-08-28')
        ->and($response->json('data.daily'))->toHaveCount(366);
});

it('answers an empty range shape for a store that carries nothing', function (): void {
    $store = m10_store();

    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics?from=2026-08-01&to=2026-08-03')
        ->assertOk()
        ->assertJsonPath('data.store_views', 0)
        ->assertJsonPath('data.product_views', 0)
        ->assertJsonPath('data.products', [])
        ->assertJsonCount(3, 'data.daily');
});

/*
|--------------------------------------------------------------------------
| The two halves meeting
|--------------------------------------------------------------------------
*/

it('reads back through analytics what the public endpoint recorded', function (): void {
    $product = m10_product();
    $store = m10_store();
    m10_carry($store, $product);

    CarbonImmutable::setTestNow('2026-08-28T09:00:00Z');

    $this->postJson("/api/products/{$product->slug}/views", ['store_id' => $store->id])
        ->assertCreated();

    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/stores/mine/analytics')
        ->assertOk()
        ->assertJsonPath('data.store_views', 1)
        ->assertJsonPath('data.products.0.slug', $product->slug);

    CarbonImmutable::setTestNow();
});
