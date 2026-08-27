<?php

declare(strict_types=1);

use App\Jobs\NotifyNearbyAvailability;
use App\Jobs\NotifyPriceDrop;
use App\Models\Attachment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Models\WishlistItem;
use App\Notifications\NearbyAvailability;
use App\Notifications\PriceDropped;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

/**
 * M8 Listing management, the wishlist, and the two alerts.
 *
 * The build plan's list for this milestone, item by item: a price decrease queuing
 * alerts and an increase not doing so, repeat alerts suppressed by the last notified
 * price, the live flag recomputed on both attachment creation and deletion, zero and
 * negative prices rejected, and the product remaining visible after its last seller
 * leaves.
 *
 * Helpers are prefixed and local to this file so it runs alone.
 */
function m8_product(string $name = 'Aurora Field Recorder FR-2'): Product
{
    $product = Product::factory()->create(['name' => $name, 'category' => 'Audio']);

    Variant::factory()->for($product)->combination([])->create();

    return $product->refresh();
}

function m8_store(?float $lat = null, ?float $lng = null): Store
{
    $store = Store::factory()->for(User::factory())->create();

    if ($lat !== null && $lng !== null) {
        $store->forceFill(['latitude' => $lat, 'longitude' => $lng])->save();
    }

    return $store->refresh();
}

function m8_listing(Store $store, Product $product, int $priceMinor = 450_000): Attachment
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

/*
|--------------------------------------------------------------------------
| EP-25 Changing a price and availability
|--------------------------------------------------------------------------
*/

it('lets a seller change their own price', function (): void {
    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 429_900])
        ->assertOk()
        ->assertJsonPath('data.attachment_id', $listing->id)
        ->assertJsonPath('data.price_minor', 429_900)
        ->assertJsonPath('data.currency', 'LKR');

    expect($listing->refresh()->price_minor)->toBe(429_900);
});

it('lets a seller mark a listing unavailable without restating the price', function (): void {
    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['is_available' => false])
        ->assertOk()
        ->assertJsonPath('data.is_available', false)
        ->assertJsonPath('data.price_minor', 450_000);
});

it('rejects a zero price', function (): void {
    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    // A free listing is not a listing.
    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 0])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect($listing->refresh()->price_minor)->toBe(450_000);
});

it('rejects a negative price', function (): void {
    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    // A negative price is not a discount.
    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => -100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('rejects a decimal price', function (): void {
    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    // Money crosses this boundary as an integer in the smallest unit, in both
    // directions. A decimal here means somebody sent rupees where cents were meant.
    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 4299.5])
        ->assertStatus(422);
});

it('rejects an empty update rather than reporting success', function (): void {
    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", [])
        ->assertStatus(422);
});

it('ignores any attempt to change the product through a listing', function (): void {
    $product = m8_product();
    $store = m8_store();
    $listing = m8_listing($store, $product);

    /*
     * Invariant 1. The only seller path into product data is a proposal, so these keys
     * must be dropped rather than applied. Asserted on the record, not on the response,
     * because what matters is that nothing was written.
     */
    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", [
            'price_minor' => 400_000,
            'name' => 'Renamed By A Seller',
            'category' => 'Hijacked',
            'attribute_values' => ['Colour' => 'Nonsense'],
        ])
        ->assertOk();

    expect($product->refresh()->name)->toBe('Aurora Field Recorder FR-2')
        ->and($product->category)->toBe('Audio');
});

it('refuses to let a seller touch another store listing', function (): void {
    $listing = m8_listing(m8_store(), m8_product());
    $intruder = m8_store();

    // 404 rather than 403: confirming the listing exists tells a competitor something
    // about their inventory.
    $this->actingAs($intruder->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 1])
        ->assertNotFound();

    expect($listing->refresh()->price_minor)->toBe(450_000);
});

it('refuses listing management to a user with no store', function (): void {
    $listing = m8_listing(m8_store(), m8_product());

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 1])
        ->assertStatus(403)
        ->assertJsonPath('code', 'store_required');
});

/*
|--------------------------------------------------------------------------
| The price drop alert
|--------------------------------------------------------------------------
*/

it('queues a price drop alert when the price falls', function (): void {
    Bus::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 400_000])
        ->assertOk();

    Bus::assertDispatched(
        NotifyPriceDrop::class,
        fn (NotifyPriceDrop $job): bool => $job->attachmentId === $listing->id
            && $job->newPriceMinor === 400_000,
    );
});

it('queues nothing when the price rises', function (): void {
    Bus::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    // A buyer asked to hear when something got cheaper. Telling them it got dearer
    // answers a question nobody asked.
    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 500_000])
        ->assertOk();

    Bus::assertNotDispatched(NotifyPriceDrop::class);
});

it('queues nothing when the price is set to what it already was', function (): void {
    Bus::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    $this->actingAs($store->user, 'sanctum')
        ->patchJson("/api/attachments/{$listing->id}", ['price_minor' => 450_000])
        ->assertOk();

    Bus::assertNotDispatched(NotifyPriceDrop::class);
});

it('sends the alert and stamps the notified price', function (): void {
    Notification::fake();

    $store = m8_store();
    $product = m8_product();
    $listing = m8_listing($store, $product, 450_000);

    $watcher = User::factory()->create();
    $item = WishlistItem::create(['user_id' => $watcher->id, 'variant_id' => $listing->variant_id]);

    $listing->forceFill(['price_minor' => 400_000])->save();
    (new NotifyPriceDrop($listing->id, 400_000))->handle();

    Notification::assertSentTo($watcher, PriceDropped::class);

    // The stamp is what suppresses the next one.
    expect($item->refresh()->last_notified_price_minor)->toBe(400_000);
});

it('suppresses a repeat alert at the same or a higher price', function (): void {
    Notification::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product(), 400_000);

    $watcher = User::factory()->create();
    WishlistItem::create([
        'user_id' => $watcher->id,
        'variant_id' => $listing->variant_id,
        // Already told about 400,000.
        'last_notified_price_minor' => 400_000,
    ]);

    /*
     * A seller oscillating a price around a threshold would otherwise send an email on
     * every downswing, and the buyer would learn to ignore all of them.
     */
    (new NotifyPriceDrop($listing->id, 400_000))->handle();

    Notification::assertNothingSent();
});

it('still alerts when the price falls below the last one the buyer was told', function (): void {
    Notification::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product(), 350_000);

    $watcher = User::factory()->create();
    $item = WishlistItem::create([
        'user_id' => $watcher->id,
        'variant_id' => $listing->variant_id,
        'last_notified_price_minor' => 400_000,
    ]);

    (new NotifyPriceDrop($listing->id, 350_000))->handle();

    Notification::assertSentTo($watcher, PriceDropped::class);
    expect($item->refresh()->last_notified_price_minor)->toBe(350_000);
});

it('does not alert on a listing that is out of stock', function (): void {
    Notification::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product(), 400_000);
    $listing->forceFill(['is_available' => false])->save();

    $watcher = User::factory()->create();
    WishlistItem::create(['user_id' => $watcher->id, 'variant_id' => $listing->variant_id]);

    // Telling somebody the price fell on something nobody can buy sends them to an
    // empty shelf.
    (new NotifyPriceDrop($listing->id, 400_000))->handle();

    Notification::assertNothingSent();
});

it('does not alert when the price moved again before the job ran', function (): void {
    Notification::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product(), 420_000);

    $watcher = User::factory()->create();
    WishlistItem::create(['user_id' => $watcher->id, 'variant_id' => $listing->variant_id]);

    // The job was queued for 400,000 but the price is 420,000 by the time it runs. An
    // email quoting a price nobody can buy at is worse than no email.
    (new NotifyPriceDrop($listing->id, 400_000))->handle();

    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The nearby availability alert
|--------------------------------------------------------------------------
*/

it('queues the nearby alert whenever an attachment is created', function (): void {
    Bus::fake();

    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    // Hung off the model rather than a controller, so every path that creates a
    // listing is covered: confirmation, the wizard, and an approved proposal.
    Bus::assertDispatched(
        NotifyNearbyAvailability::class,
        fn (NotifyNearbyAvailability $job): bool => $job->attachmentId === $listing->id,
    );
});

it('tells a buyer inside the radius', function (): void {
    Notification::fake();

    // Colombo, and a buyer a couple of kilometres away.
    $store = m8_store(6.9271, 79.8612);
    $listing = m8_listing($store, m8_product());

    $near = User::factory()->create(['latitude' => 6.9350, 'longitude' => 79.8500]);
    WishlistItem::create(['user_id' => $near->id, 'variant_id' => $listing->variant_id]);

    (new NotifyNearbyAvailability($listing->id))->handle();

    Notification::assertSentTo($near, NearbyAvailability::class);
});

it('says nothing to a buyer outside the radius', function (): void {
    Notification::fake();

    $store = m8_store(6.9271, 79.8612);
    $listing = m8_listing($store, m8_product());

    // Jaffna, roughly 300 km away.
    $far = User::factory()->create(['latitude' => 9.6615, 'longitude' => 80.0255]);
    WishlistItem::create(['user_id' => $far->id, 'variant_id' => $listing->variant_id]);

    (new NotifyNearbyAvailability($listing->id))->handle();

    Notification::assertNothingSent();
});

it('says nothing to a buyer who never shared a location', function (): void {
    Notification::fake();

    $store = m8_store(6.9271, 79.8612);
    $listing = m8_listing($store, m8_product());

    /*
     * The documented cost of declining the location prompt. The platform cannot say a
     * shop is nearby without knowing where the buyer is, and it does not fall back to
     * telling everyone, which would turn the alert into a marketing email.
     */
    $unknown = User::factory()->create(['latitude' => null, 'longitude' => null]);
    WishlistItem::create(['user_id' => $unknown->id, 'variant_id' => $listing->variant_id]);

    (new NotifyNearbyAvailability($listing->id))->handle();

    Notification::assertNothingSent();
});

it('does not tell the seller about their own wishlist', function (): void {
    Notification::fake();

    $store = m8_store(6.9271, 79.8612);
    $listing = m8_listing($store, m8_product());

    $store->user->forceFill(['latitude' => 6.9271, 'longitude' => 79.8612])->save();
    WishlistItem::create(['user_id' => $store->user_id, 'variant_id' => $listing->variant_id]);

    // They know what they just listed.
    (new NotifyNearbyAvailability($listing->id))->handle();

    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| EP-26 Detaching, and the live flag
|--------------------------------------------------------------------------
*/

it('marks a store live when its first attachment is created', function (): void {
    $store = m8_store();

    expect($store->is_live)->toBeFalse();

    m8_listing($store, m8_product());

    expect($store->refresh()->is_live)->toBeTrue();
});

it('darkens a store when its last listing is removed', function (): void {
    $store = m8_store();
    $listing = m8_listing($store, m8_product());

    expect($store->refresh()->is_live)->toBeTrue();

    $this->actingAs($store->user, 'sanctum')
        ->deleteJson("/api/attachments/{$listing->id}")
        ->assertOk()
        ->assertJsonPath('data.detached', true)
        // The one thing the seller needs to know at that moment.
        ->assertJsonPath('data.store_is_live', false);

    expect($store->refresh()->is_live)->toBeFalse();
});

it('keeps a store live while it still carries something else', function (): void {
    $store = m8_store();
    $first = m8_listing($store, m8_product('First Product'));
    m8_listing($store, m8_product('Second Product'));

    $this->actingAs($store->user, 'sanctum')
        ->deleteJson("/api/attachments/{$first->id}")
        ->assertOk()
        ->assertJsonPath('data.store_is_live', true);

    expect($store->refresh()->is_live)->toBeTrue();
});

it('leaves the product visible after its last seller leaves', function (): void {
    $product = m8_product();
    $store = m8_store();
    $listing = m8_listing($store, $product);

    $this->actingAs($store->user, 'sanctum')
        ->deleteJson("/api/attachments/{$listing->id}")
        ->assertOk();

    /*
     * The canonical record is platform owned and outlives every seller on it. It keeps
     * its URL, its variants, and its version history, and simply reports no sellers.
     */
    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.slug', $product->slug)
        ->assertJsonPath('data.seller_count', 0);

    $this->getJson("/api/products/{$product->slug}/variants")->assertOk();

    expect(Product::whereKey($product->id)->exists())->toBeTrue();
});

it('refuses to let a seller detach another store listing', function (): void {
    $listing = m8_listing(m8_store(), m8_product());
    $intruder = m8_store();

    $this->actingAs($intruder->user, 'sanctum')
        ->deleteJson("/api/attachments/{$listing->id}")
        ->assertNotFound();

    expect(Attachment::whereKey($listing->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| EP-36 to EP-38 The wishlist
|--------------------------------------------------------------------------
*/

it('saves a variant and lists it with the cheapest current listing', function (): void {
    $product = m8_product();
    $store = m8_store();
    m8_listing($store, $product, 450_000);
    m8_listing(m8_store(), $product, 399_000);

    $buyer = User::factory()->create();
    $variantId = $product->variants()->first()->id;

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $variantId])
        ->assertOk()
        ->assertJsonPath('data.variant_id', $variantId);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/wishlist')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lowest_price_minor', 399_000)
        ->assertJsonPath('data.0.seller_count', 2)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('answers the existing item when a variant is saved twice', function (): void {
    $product = m8_product();
    $buyer = User::factory()->create();
    $variantId = $product->variants()->first()->id;

    $first = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $variantId])
        ->assertOk()
        ->json('data.id');

    // A buyer pressing save twice meant it twice. There is nothing to apologise for.
    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $variantId])
        ->assertOk()
        ->assertJsonPath('data.id', $first);

    expect(WishlistItem::where('user_id', $buyer->id)->count())->toBe(1);
});

it('reports a null lowest price for a variant nobody carries', function (): void {
    $product = m8_product();
    $buyer = User::factory()->create();

    // A normal state, not missing data: being told when somebody starts stocking it is
    // what the wishlist is for.
    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $product->variants()->first()->id])
        ->assertOk()
        ->assertJsonPath('data.lowest_price_minor', null)
        ->assertJsonPath('data.seller_count', 0);
});

it('ignores an unavailable listing when reporting the lowest price', function (): void {
    $product = m8_product();
    $cheap = m8_listing(m8_store(), $product, 300_000);
    $cheap->forceFill(['is_available' => false])->save();
    m8_listing(m8_store(), $product, 450_000);

    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $product->variants()->first()->id])
        ->assertOk()
        ->assertJsonPath('data.lowest_price_minor', 450_000)
        ->assertJsonPath('data.seller_count', 1);
});

it('refuses to save a variant that does not exist', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => 999_999])
        ->assertNotFound();
});

it('removes a saved variant', function (): void {
    $product = m8_product();
    $buyer = User::factory()->create();

    $id = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $product->variants()->first()->id])
        ->json('data.id');

    $this->actingAs($buyer, 'sanctum')
        ->deleteJson("/api/wishlist/{$id}")
        ->assertOk()
        ->assertJsonPath('data.removed', true);

    expect(WishlistItem::whereKey($id)->exists())->toBeFalse();
});

it('refuses to remove another buyer wishlist item', function (): void {
    $product = m8_product();
    $owner = User::factory()->create();

    $id = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $product->variants()->first()->id])
        ->json('data.id');

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->deleteJson("/api/wishlist/{$id}")
        ->assertNotFound();

    expect(WishlistItem::whereKey($id)->exists())->toBeTrue();
});

it('keeps one wishlist per user rather than per role', function (): void {
    $product = m8_product();

    // A user who runs a store saves things like anyone else. One account, both roles,
    // and no mode switch anywhere in this platform.
    $seller = m8_store();

    $this->actingAs($seller->user, 'sanctum')
        ->postJson('/api/wishlist', ['variant_id' => $product->variants()->first()->id])
        ->assertOk();

    $this->actingAs($seller->user, 'sanctum')
        ->getJson('/api/wishlist')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses the wishlist without a token', function (): void {
    $this->getJson('/api/wishlist')->assertUnauthorized();
    $this->postJson('/api/wishlist', ['variant_id' => 1])->assertUnauthorized();
});
