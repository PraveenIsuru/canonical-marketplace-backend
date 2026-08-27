<?php

declare(strict_types=1);

use App\Contracts\GeocodingProvider;
use App\Models\Attachment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Geocoding\FakeGeocodingProvider;
use App\Services\Geocoding\GeocodingResult;
use App\Services\Stores\StoreRegistrationService;

/**
 * M4 Seller onboarding. EP-16, EP-17, EP-18, EP-54.
 *
 * The surprising behaviour in this milestone is that a failed geocode returns 201 with
 * null coordinates rather than a 4xx, and much of this file exists to pin that down.
 *
 * No test touches the network: the fake geocoding adapter is bound throughout.
 */

/** Forces every geocoding attempt to fail for the current test. */
function withFailingGeocoder(): void
{
    app()->instance(GeocodingProvider::class, new FakeGeocodingProvider(shouldFail: true));
}

/** @return array<string, string> */
function storePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Fort Electronics',
        'category' => 'Electronics',
        'contact_email' => 'shop@example.com',
        'contact_phone' => '+94112345678',
        'address_line' => '42 Galle Road',
        'city' => 'Colombo',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| EP-16 Register a store
|--------------------------------------------------------------------------
*/

it('creates a store and geocodes the address', function (): void {
    $response = $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/stores', storePayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Fort Electronics')
        ->assertJsonPath('data.geocode_source', 'locationiq')
        ->assertJsonPath('data.geocoding_failed', false);

    expect($response->json('data.latitude'))->toBeFloat()
        ->and($response->json('data.longitude'))->toBeFloat();
});

it('leaves a new store dark', function (): void {
    // A store becomes visible to buyers only once it holds an attachment, which cannot
    // happen during onboarding. Nothing in this milestone may set the flag.
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/stores', storePayload())
        ->assertCreated()
        ->assertJsonPath('data.is_live', false);

    expect(Store::sole()->is_live)->toBeFalse();
});

it('creates the store anyway when geocoding fails, answering 201 rather than a 4xx', function (): void {
    withFailingGeocoder();

    /*
     * The single most surprising behaviour in seller onboarding. Refusing here would
     * discard details the seller correctly submitted and turn a defined fallback into
     * an error they did nothing to cause.
     */
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/stores', storePayload())
        ->assertCreated()
        ->assertJsonPath('data.geocoding_failed', true)
        ->assertJsonPath('data.latitude', null)
        ->assertJsonPath('data.longitude', null)
        ->assertJsonPath('data.geocode_source', null)
        // The submitted details survive. That is the point of not refusing.
        ->assertJsonPath('data.name', 'Fort Electronics')
        ->assertJsonPath('data.address_line', '42 Galle Road');

    expect(Store::sole()->latitude)->toBeNull();
});

it('treats an unresolvable address as a geocoding failure, not an error', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/stores', storePayload(['city' => 'Atlantis']))
        ->assertCreated()
        ->assertJsonPath('data.geocoding_failed', true)
        ->assertJsonPath('data.latitude', null);
});

it('refuses a second store with store_exists', function (): void {
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/stores', storePayload())
        ->assertStatus(409)
        ->assertJsonPath('code', 'store_exists');

    // One user, one store. The refusal must not have written anything.
    expect(Store::where('user_id', $user->id)->count())->toBe(1);
});

it('validates the submitted details', function (array $payload, string $field): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/stores', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['code', 'message', 'errors' => [$field]]);
})->with([
    'missing name' => [fn () => storePayload(['name' => '']), 'name'],
    'missing city' => [fn () => storePayload(['city' => '']), 'city'],
    'bad email' => [fn () => storePayload(['contact_email' => 'not-an-email']), 'contact_email'],
    'missing address' => [fn () => storePayload(['address_line' => '']), 'address_line'],
]);

it('never lets a payload set coordinates or visibility', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/stores', storePayload([
            'is_live' => true,
            'latitude' => 1.0,
            'longitude' => 1.0,
            'geocode_source' => 'manual_pin',
        ]))
        ->assertCreated();

    $store = Store::sole();

    // Coordinates come from the provider or the pin endpoint. Visibility is derived.
    expect($store->is_live)->toBeFalse()
        ->and($store->geocode_source)->toBe('locationiq')
        ->and((float) $store->latitude)->not->toBe(1.0);
});

it('refuses store registration without a token', function (): void {
    $this->postJson('/api/stores', storePayload())
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

/*
|--------------------------------------------------------------------------
| EP-54 The seller's own store
|--------------------------------------------------------------------------
*/

it('returns the full record for the settings form', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->inCity('Kandy')->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/stores/mine')
        ->assertOk()
        ->assertJsonPath('data.id', $store->id)
        ->assertJsonPath('data.city', 'Kandy')
        // The owner sees the geocode source; a buyer never does.
        ->assertJsonStructure(['data' => ['geocode_source', 'is_live', 'contact_email', 'address_line']]);
});

it('refuses seller routes for a user with no store', function (string $method, string $uri): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->json($method, $uri, ['latitude' => 6.9, 'longitude' => 79.8])
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
})->with([
    'own store' => ['GET', '/api/stores/mine'],
    'update' => ['PATCH', '/api/stores/mine'],
    'pin' => ['POST', '/api/stores/mine/pin'],
]);

/*
|--------------------------------------------------------------------------
| EP-17 Manual pin placement
|--------------------------------------------------------------------------
*/

it('places a pin and records the manual source', function (): void {
    withFailingGeocoder();

    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->postJson('/api/stores', storePayload())->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/stores/mine/pin', ['latitude' => 6.9271, 'longitude' => 79.8612])
        ->assertOk()
        ->assertJsonPath('data.latitude', 6.9271)
        ->assertJsonPath('data.longitude', 79.8612)
        // Distinguishing a hand placed pin from a provider result is what makes later
        // data quality review possible.
        ->assertJsonPath('data.geocode_source', 'manual_pin');
});

it('derives the postgis point from a placed pin', function (): void {
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/stores/mine/pin', ['latitude' => 6.9271, 'longitude' => 79.8612])
        ->assertOk();

    // location is a generated column, so the pair and the point cannot disagree. This
    // asserts the generation actually happened rather than trusting it.
    $row = DB::selectOne(
        'select ST_Y(location::geometry) as lat, ST_X(location::geometry) as lng from stores where user_id = ?',
        [$user->id],
    );

    expect(round((float) $row->lat, 4))->toBe(6.9271)
        ->and(round((float) $row->lng, 4))->toBe(79.8612);
});

it('refuses a pin outside plausible bounds', function (float $lat, float $lng): void {
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/stores/mine/pin', ['latitude' => $lat, 'longitude' => $lng])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
})->with([
    'latitude too high' => [91.0, 79.8],
    'longitude too low' => [6.9, -181.0],
]);

it('does not make a store live by placing a pin', function (): void {
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/stores/mine/pin', ['latitude' => 6.9271, 'longitude' => 79.8612])
        ->assertOk()
        ->assertJsonPath('data.is_live', false);
});

/*
|--------------------------------------------------------------------------
| EP-18 Store settings
|--------------------------------------------------------------------------
*/

it('updates details without re-geocoding when the address did not change', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->inCity('Colombo')->create();
    $originalLatitude = (float) $store->latitude;

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/stores/mine', ['contact_phone' => '+94119999999'])
        ->assertOk()
        ->assertJsonPath('data.contact_phone', '+94119999999')
        ->assertJsonPath('data.geocoding_failed', false);

    // Re-geocoding on a phone edit would spend a provider call answering a question
    // nobody asked, and could replace good coordinates with a worse match.
    expect((float) $store->refresh()->latitude)->toBe($originalLatitude);
});

it('re-geocodes when the address changes', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->inCity('Colombo')->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/stores/mine', ['city' => 'Kandy', 'address_line' => '9 Temple Street'])
        ->assertOk()
        ->assertJsonPath('data.city', 'Kandy')
        ->assertJsonPath('data.geocoding_failed', false);

    // Kandy is well north of Colombo, so a successful re-geocode moves the latitude.
    expect((float) $store->refresh()->latitude)->toBeGreaterThan(7.0);
});

it('keeps the previous coordinates when re-geocoding fails', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->inCity('Colombo')->create();
    $originalLatitude = (float) $store->latitude;

    withFailingGeocoder();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/stores/mine', ['address_line' => '99 Somewhere Else'])
        ->assertOk()
        // Signalled so the client can offer pin placement, but not fatal.
        ->assertJsonPath('data.geocoding_failed', true)
        ->assertJsonPath('data.address_line', '99 Somewhere Else');

    /*
     * The seller had a working location a moment ago. Discarding it because an edit to
     * an unrelated part of the address failed would silently remove the store from
     * every proximity sorted list.
     */
    expect((float) $store->refresh()->latitude)->toBe($originalLatitude);
});

it('never changes visibility when details are edited', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/stores/mine', ['name' => 'Renamed Shop'])
        ->assertOk()
        ->assertJsonPath('data.is_live', false);

    expect($store->refresh()->is_live)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The session user, now that stores exist
|--------------------------------------------------------------------------
*/

it('returns a null store for a user who has none', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.store', null);
});

it('returns a minimal store object once the user has one', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->for($user)->create(['name' => 'Fort Electronics']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.store.id', $store->id)
        ->assertJsonPath('data.store.name', 'Fort Electronics')
        ->assertJsonPath('data.store.is_live', false);
});

it('keeps the session store minimal', function (): void {
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    $store = $this->actingAs($user, 'sanctum')->getJson('/api/user')->json('data.store');

    // Every authenticated page makes this call. The settings form uses EP-54 instead.
    expect(array_keys($store))->toBe(['id', 'name', 'is_live']);
});

/*
|--------------------------------------------------------------------------
| Invariant 12: a store is visible if and only if it holds an attachment
|--------------------------------------------------------------------------
*/

it('keeps a newly registered store out of buyer seller lists', function (): void {
    $product = Product::factory()->create();
    $variant = Variant::factory()->for($product)->default()->create();

    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->postJson('/api/stores', storePayload())->assertCreated();

    // Registered, geocoded, and still invisible, because it carries nothing.
    $this->getJson("/api/products/{$product->slug}/sellers")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    // And the moment it carries something, it appears.
    Attachment::factory()->create([
        'store_id' => Store::sole()->id,
        'variant_id' => $variant->id,
        'product_id' => $product->id,
        'price_minor' => 1000,
    ]);

    $this->getJson("/api/products/{$product->slug}/sellers")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

/*
|--------------------------------------------------------------------------
| The fake adapter every other test depends on
|--------------------------------------------------------------------------
*/

it('resolves a known city and refuses an unknown one', function (): void {
    $geocoder = new FakeGeocodingProvider;

    expect($geocoder->geocode('42 Galle Road', 'Colombo')->succeeded)->toBeTrue()
        ->and($geocoder->geocode('1 Nowhere St', 'Atlantis')->failed())->toBeTrue();
});

it('treats out of range provider coordinates as a failure', function (): void {
    // A provider answering with nonsense is no more useful than one that does not
    // answer, and letting it through would put a store somewhere it is not.
    expect(GeocodingResult::fromProvider(999.0, 79.8)->failed())->toBeTrue()
        ->and(GeocodingResult::fromProvider(null, null)->failed())->toBeTrue();
});

it('records the two geocode sources distinctly', function (): void {
    expect(StoreRegistrationService::SOURCE_PROVIDER)->toBe('locationiq')
        ->and(StoreRegistrationService::SOURCE_MANUAL)->toBe('manual_pin');
});
