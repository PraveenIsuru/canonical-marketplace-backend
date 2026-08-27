<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\AttachSession;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The twelve invariants from section 3 of development-docs/backend-build-plan.md.
 *
 * This file exists from M0, before the endpoints it guards, and grows as each
 * milestone lands. That is deliberate. Written at the end it would be an exercise in
 * describing whatever the code already does; written first it fails when a future
 * change quietly breaks a rule the design depends on.
 *
 * Assertions marked "pending" name the milestone that makes them meaningful. Add the
 * real assertion when that milestone lands rather than writing a new file.
 */

/*
|--------------------------------------------------------------------------
| 3. The confidence score never leaves the server
|--------------------------------------------------------------------------
*/

it('never exposes forbidden fields from any api response', function (): void {
    /*
     * The three fields that must never cross the wire, per section 6 of the contract.
     * A single careless API Resource breaks this, and it is not the kind of thing a
     * screen review catches, so it is asserted rather than trusted.
     */
    $forbidden = ['confidence_score', 'confidence_band', 'created_by_store_id'];

    $body = $this->getJson('/api/health')->getContent();

    foreach ($forbidden as $field) {
        expect($body)->not->toContain($field);
    }
})->todo('Extend to every serialiser as endpoints land, from M6 onward');

/*
|--------------------------------------------------------------------------
| 9. Public catalogue routes work with no token and never resolve a session
|--------------------------------------------------------------------------
*/

it('serves public routes with no token', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertHeader('X-Access-Level', 'public');
});

it('does not change public route behaviour when a token happens to be present', function (): void {
    $anonymous = $this->getJson('/api/health')->json();

    $authenticated = $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/health')
        ->json();

    // The time field naturally differs, so compare the part that must not.
    expect($authenticated['data']['status'])->toBe($anonymous['data']['status']);
});

it('starts no session on public routes', function (): void {
    $response = $this->getJson('/api/health')->assertOk();

    /*
     * The API middleware group carries no session or cookie middleware, so a public
     * catalogue request must not come back trying to set one. Most catalogue traffic
     * is anonymous, and a session cookie on those responses would both cost latency
     * and make the responses uncacheable by any shared cache.
     */
    $cookies = $response->headers->getCookies();

    expect($cookies)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Access levels refuse correctly
|--------------------------------------------------------------------------
*/

it('refuses a seller route for a user with no store', function (): void {
    Route::middleware(['auth:sanctum', 'store'])
        ->get('/api/_inv/seller', fn () => response()->json(['data' => true]));

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/_inv/seller')
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

it('refuses an admin route for a non administrator', function (): void {
    Route::middleware(['auth:sanctum', 'admin'])
        ->get('/api/_inv/admin', fn () => response()->json(['data' => true]));

    $this->actingAs(User::factory()->create(['is_admin' => false]), 'sanctum')
        ->getJson('/api/_inv/admin')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('allows an admin route for an administrator', function (): void {
    Route::middleware(['auth:sanctum', 'admin'])
        ->get('/api/_inv/admin-ok', fn () => response()->json(['data' => true]));

    $this->actingAs(User::factory()->create(['is_admin' => true]), 'sanctum')
        ->getJson('/api/_inv/admin-ok')
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Roles are derived, never stored
|--------------------------------------------------------------------------
*/

it('keeps is_admin out of mass assignment', function (): void {
    /*
     * A registration payload must never be able to make its own account an
     * administrator. is_admin is deliberately absent from the model's fillable list.
     */
    $user = new User;
    $user->fill(['name' => 'Test', 'email' => 't@example.com', 'is_admin' => true]);

    expect($user->is_admin)->not->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| PostGIS is available, because distance is computed in the database
|--------------------------------------------------------------------------
*/

it('has postgis enabled', function (): void {
    $version = DB::selectOne('select postgis_version() as v')->v;

    expect($version)->toBeString()->not->toBeEmpty();
});

it('computes distance in the database rather than in php', function (): void {
    // Colombo to Kandy, roughly 94 km. Proves the geography type and the distance
    // function both work before the seller list query depends on them at M2.
    $metres = DB::selectOne(
        'select ST_Distance(
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
        ) as d',
        [79.8612, 6.9271, 80.6337, 7.2906]
    )->d;

    expect($metres / 1000)->toBeGreaterThan(80.0)->toBeLessThan(110.0);
});

/*
|--------------------------------------------------------------------------
| Pending invariants
|--------------------------------------------------------------------------
| Each names the milestone that makes it assertable. Replace the todo with the real
| assertion when that milestone lands.
*/

it('never lets a seller write to a product, attribute, or variant', function (): void {
    $product = Product::factory()->create(['name' => 'Aurora Field Recorder FR-2']);
    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Colour',
        'options' => ['Black'],
        'position' => 0,
    ]);
    $variant = Variant::factory()->for($product)->combination(['Colour' => 'Black'])->create();

    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    /*
     * There is no seller route that writes to a canonical record. Asserting the absence
     * of a capability means asserting no route offers it, so this walks the registered
     * routes rather than guessing at paths that might exist.
     */
    $writable = collect(Route::getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
        ->filter(fn ($route): bool => (bool) array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values();

    // The only writes a seller has are to their own store and their own location.
    // Nothing addresses a product, an attribute, or a variant.
    foreach ($writable as $route) {
        expect($route)->not->toContain('products')
            ->and($route)->not->toContain('variants')
            ->and($route)->not->toContain('attributes');
    }

    // And the record is unchanged by the one seller flow that touches it at all.
    $before = [$product->name, $product->specifications];

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertOk();

    expect([$product->refresh()->name, $product->specifications])->toBe($before)
        ->and($variant->refresh()->attribute_values)->toBe(['Colour' => 'Black']);
});

it('never removes a generated variant combination', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->create();

    $session = AttachSession::create([
        'store_id' => $store->id,
        'type' => AttachSession::TYPE_WIZARD,
        'questions' => [['id' => 'q1', 'attribute' => 'brand', 'text' => 'Who makes it?']],
        'draft' => ['name' => 'Harbour Deck Lantern'],
        'expires_at' => now()->addHour(),
    ]);

    // Three combinations defined, one of them carried.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', [
            'session_id' => $session->id,
            'answers' => ['q1' => 'Harbour'],
            'name' => 'Harbour Deck Lantern',
            'category' => 'Home',
            'attributes' => [['name' => 'Finish', 'options' => ['Brass', 'Copper', 'Steel']]],
            'carried_variants' => [
                ['attribute_values' => ['Finish' => 'Brass'], 'price_minor' => 850000],
            ],
        ])
        ->assertCreated();

    /*
     * All three exist, and the two nobody carries are not hidden, deleted, or marked
     * inactive. They show as having no sellers yet.
     *
     * There is no deletion path to test against, and that absence is the invariant.
     * What can be asserted is that generation produced every combination and that the
     * uncarried ones survived a run that had every opportunity to skip them.
     */
    $product = Product::sole();

    expect($product->variants()->count())->toBe(3)
        ->and(Attachment::count())->toBe(1);

    $uncarried = $product->variants()->whereJsonContains('attribute_values->Finish', 'Copper')->sole();

    expect($uncarried->attachments()->count())->toBe(0);

    // And the public variant list returns it, rather than omitting a combination with
    // no sellers, which would silently reintroduce removal from the buyer's view.
    $this->getJson("/api/products/{$product->slug}/variants")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('creates no attachment row while a proposal is pending', function (): void {
    $product = Product::factory()->create([
        'name' => 'Aurora Field Recorder FR-2',
        'specifications' => ['inputs' => '2'],
    ]);
    Variant::factory()->for($product)->combination(['Colour' => 'Black'])->create();

    $user = User::factory()->create();
    $store = Store::factory()->for($user)->create();

    $session = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertOk()->json('data.session_id');

    $answers = [];
    foreach (AttachSession::findOrFail($session)->questions as $question) {
        $answers[$question['id']] = $question['attribute'] === 'inputs'
            ? '4'                                        // Disagrees, so a proposal opens.
            : (string) ($question['current_value'] ?? 'unchanged');
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session,
            'answers' => $answers,
            'variant_ids' => [$product->variants()->first()->id],
            'price_minor' => 450_000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.outcome', 'proposal_created');

    /*
     * The absence of the row *is* the block. Not a flag beside an attachment, not a
     * disabled state on one: there is nothing to disable, which is why no query
     * anywhere can forget to check it.
     */
    expect(Attachment::where('store_id', $store->id)->count())->toBe(0)
        ->and($store->refresh()->is_live)->toBeFalse();

    // And the product remains as it was. The change is proposed, not applied.
    expect($product->refresh()->specifications['inputs'])->toBe('2');
});

it('creates a version only on an accepted proposal or an administrator edit', function (): void {
    //
})->todo('M7 and M11');

it('deletes a verification photograph whether verification passed or failed', function (): void {
    //
})->todo('M9');

it('falls back to keyword search rather than queueing, for buyer search only', function (): void {
    //
})->todo('M3');

it('keeps a store visible if and only if it holds at least one attachment', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->create();

    // Registered, located, and carrying nothing. Onboarding cannot make a store live,
    // and M5 is the first milestone in which anything can.
    expect($store->is_live)->toBeFalse();

    $this->getJson("/api/stores/{$store->id}")->assertNotFound();

    $session = AttachSession::create([
        'store_id' => $store->id,
        'type' => AttachSession::TYPE_WIZARD,
        'questions' => [['id' => 'q1', 'attribute' => 'brand', 'text' => 'Who makes it?']],
        'draft' => ['name' => 'Harbour Deck Lantern'],
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/wizard/submit', [
            'session_id' => $session->id,
            'answers' => ['q1' => 'Harbour'],
            'name' => 'Harbour Deck Lantern',
            'category' => 'Home',
            'attributes' => [],
            'carried_variants' => [['attribute_values' => [], 'price_minor' => 850000]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.store_is_live', true);

    // The flag and the buyer's view of it move together. A stored flag that disagreed
    // with the attachment count is the failure this guards against.
    expect($store->refresh()->is_live)->toBeTrue();

    $this->getJson("/api/stores/{$store->id}")->assertOk();

    // And the other direction. The endpoint that detaches lands at M8, so the
    // recomputation is driven directly here rather than through a route that does not
    // exist yet.
    $store->attachments()->delete();
    $store->recomputeLiveFlag();

    expect($store->refresh()->is_live)->toBeFalse();

    $this->getJson("/api/stores/{$store->id}")->assertNotFound();
});

it('sends every price as an integer in the smallest currency unit', function (): void {
    //
})->todo('M2. Assert against the seller list serialiser');
