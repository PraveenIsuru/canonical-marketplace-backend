<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVersion;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalogue\ProductVersionService;
use App\Services\Proposals\ProposalResolutionService;

/**
 * M10 Version history (EP-46, EP-47).
 *
 * The build plan's M10 list, item by item: history refused for an unattached seller
 * even though they hold the seller role, access evaluated at request time so detaching
 * removes it, rejected proposals absent from the history, and anonymous access
 * refused.
 *
 * The rejected proposal assertion is made by actually rejecting one through the
 * resolution service rather than by asserting that no code writes a version. The point
 * is what ends up in the chain, and only the real path proves that.
 *
 * Helpers are prefixed and local to this file so it runs alone.
 */
function vh_product(): Product
{
    $product = Product::factory()->create([
        'name' => 'Aurora Field Recorder FR-2',
        'category' => 'Audio',
        'specifications' => ['inputs' => '2', 'sample_rate' => '192 kHz'],
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Colour',
        'options' => ['Black', 'Grey'],
        'position' => 0,
    ]);

    Variant::factory()->for($product)->combination(['Colour' => 'Black'])->create();
    Variant::factory()->for($product)->combination(['Colour' => 'Grey'])->create();

    return $product->refresh();
}

function vh_store(): Store
{
    return Store::factory()->for(User::factory())->create();
}

function vh_carry(Store $store, Product $product): Attachment
{
    return Attachment::create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'variant_id' => $product->variants()->first()->id,
        'price_minor' => 450_000,
        'currency' => 'LKR',
        'is_available' => true,
    ]);
}

/** Version 1, as the wizard writes it when the product is created. */
function vh_firstVersion(Product $product, ?Store $store = null): ProductVersion
{
    return app(ProductVersionService::class)->record(
        $product,
        causedByStore: $store,
        causedByUser: $store?->user,
    );
}

function vh_admin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/*
|--------------------------------------------------------------------------
| Who may read a history
|--------------------------------------------------------------------------
*/

it('refuses version history to an anonymous caller', function (): void {
    $product = vh_product();
    vh_firstVersion($product);

    $this->getJson("/api/products/{$product->slug}/versions")
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    $this->getJson("/api/products/{$product->slug}/versions/1")
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('refuses version history to a signed in buyer with no store', function (): void {
    $product = vh_product();
    vh_firstVersion($product);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

it('refuses version history to a seller who does not carry this product', function (): void {
    $product = vh_product();
    $other = vh_product();
    vh_firstVersion($product);

    $seller = vh_store();

    /*
     * The seller role, held on another product entirely. Holding a store is not the
     * qualification; carrying *this* product is, which is the distinction the build
     * plan singles out for this milestone.
     */
    vh_carry($seller, $other);

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_attached');

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/1")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_attached');
});

it('lets a seller carrying the product read its history', function (): void {
    $product = vh_product();
    vh_firstVersion($product);

    $seller = vh_store();
    vh_carry($seller, $product);

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.version_number', 1);
});

it('lets an administrator with no store of their own read any history', function (): void {
    $product = vh_product();
    vh_firstVersion($product);

    // An administrator holds no store, so the seller middleware would have refused
    // this request before it reached the controller. That is why these two endpoints
    // sit in the Auth group.
    $admin = vh_admin();

    expect($admin->store)->toBeNull();

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/1")
        ->assertOk()
        ->assertJsonPath('data.version_number', 1);
});

/*
|--------------------------------------------------------------------------
| Access is a property of the request, not of the session
|--------------------------------------------------------------------------
*/

it('removes version access the moment a seller detaches, mid session', function (): void {
    $product = vh_product();
    vh_firstVersion($product);

    $seller = vh_store();
    $listing = vh_carry($seller, $product);

    // The same token, the same user, two requests either side of one detach.
    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk();

    $listing->delete();

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_attached');

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/1")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_attached');
});

it('grants version access the moment a seller attaches', function (): void {
    $product = vh_product();
    vh_firstVersion($product);

    $seller = vh_store();

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertForbidden();

    vh_carry($seller, $product);

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk();
});

it('refuses before it looks, so a stranger cannot probe which versions exist', function (): void {
    $product = vh_product();
    vh_firstVersion($product);

    $stranger = vh_store();
    vh_carry($stranger, vh_product());

    // A version that exists and one that does not answer identically to somebody who
    // may not read either.
    $this->actingAs($stranger->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/1")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_attached');

    $this->actingAs($stranger->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/99")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_attached');
});

/*
|--------------------------------------------------------------------------
| What the chain contains
|--------------------------------------------------------------------------
*/

it('lists the chain newest first', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    vh_firstVersion($product, $seller);

    $product->forceFill(['specifications' => ['inputs' => '4', 'sample_rate' => '192 kHz']])->save();
    app(ProductVersionService::class)->record($product->refresh(), causedByStore: $seller);

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.version_number', 2)
        ->assertJsonPath('data.1.version_number', 1)
        ->assertJsonPath('data.0.caused_by_store.id', $seller->id)
        ->assertJsonPath('data.0.caused_by_store.name', $seller->name);
});

it('reports nothing changed on version one and the changed part afterwards', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    vh_firstVersion($product, $seller);

    $product->forceFill(['specifications' => ['inputs' => '4', 'sample_rate' => '192 kHz']])->save();
    app(ProductVersionService::class)->record($product->refresh(), causedByStore: $seller);

    $response = $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk();

    // Version 1 created the record rather than changing it, so it lists nothing.
    expect($response->json('data.1.changed_fields'))->toBe([])
        ->and($response->json('data.0.changed_fields'))->toBe(['specifications']);
});

it('names an administrator edit without naming the administrator', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    $admin = vh_admin();

    app(ProductVersionService::class)->record(
        $product,
        causedByUser: $admin,
        isAdminOriginated: true,
    );

    $response = $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk()
        ->assertJsonPath('data.0.is_admin_originated', true)
        ->assertJsonPath('data.0.caused_by_store', null);

    // Naming the moderator who applied a change serves no seller and gives a
    // disgruntled one a target.
    expect($response->getContent())->not->toContain($admin->name)
        ->and($response->getContent())->not->toContain($admin->email);
});

it('paginates the chain per section 2', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    foreach (range(1, 3) as $ignored) {
        app(ProductVersionService::class)->record($product->refresh(), causedByStore: $seller);
    }

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('answers an empty chain for a product that has no versions yet', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

/*
|--------------------------------------------------------------------------
| EP-47 One version
|--------------------------------------------------------------------------
*/

it('returns the full record state at a version', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    vh_firstVersion($product, $seller);

    $response = $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/1")
        ->assertOk()
        ->assertJsonPath('data.version_number', 1)
        ->assertJsonPath('data.snapshot.name', 'Aurora Field Recorder FR-2')
        ->assertJsonPath('data.snapshot.category', 'Audio')
        ->assertJsonPath('data.snapshot.specifications.inputs', '2')
        ->assertJsonPath('data.snapshot.attributes.0.name', 'Colour')
        ->assertJsonCount(2, 'data.snapshot.variants');

    // A snapshot, not a diff, so the whole record is there in one row.
    expect($response->json('data.snapshot.variants.0.attribute_values'))->toBe(['Colour' => 'Black']);
});

it('answers not_found for a version number the product does not have', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    vh_firstVersion($product, $seller);

    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/7")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('answers not_found for a product that does not exist', function (): void {
    $seller = vh_store();
    vh_carry($seller, vh_product());

    $this->actingAs($seller->user, 'sanctum')
        ->getJson('/api/products/no-such-product/versions')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('serves a version belonging to this product and not to another', function (): void {
    $mine = vh_product();
    $theirs = vh_product();

    $seller = vh_store();
    vh_carry($seller, $mine);
    vh_carry($seller, $theirs);

    vh_firstVersion($mine, $seller);
    vh_firstVersion($theirs, $seller);

    // Two products, each with a version 1. The route key has to pick the right one.
    $this->actingAs($seller->user, 'sanctum')
        ->getJson("/api/products/{$theirs->slug}/versions/1")
        ->assertOk()
        ->assertJsonPath('data.snapshot.slug', $theirs->slug);
});

/*
|--------------------------------------------------------------------------
| Rejected proposals leave no trace here
|--------------------------------------------------------------------------
*/

it('leaves a rejected proposal out of the version history entirely', function (): void {
    $product = vh_product();

    $proposer = vh_store();
    $reviewerOne = vh_store();
    $reviewerTwo = vh_store();

    vh_carry($reviewerOne, $product);
    vh_carry($reviewerTwo, $product);

    vh_firstVersion($product, $reviewerOne);

    $proposal = Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => ['inputs' => ['from' => '2', 'to' => '8']],
        'confidence_band' => Proposal::BAND_LOW,
        'confidence_score' => 0.3,
        'intended_variant_ids' => [$product->variants()->first()->id],
        'intended_price_minor' => 450_000,
        'intended_currency' => 'LKR',
    ]);

    foreach ([$reviewerOne, $reviewerTwo] as $reviewer) {
        ProposalReviewer::create(['proposal_id' => $proposal->id, 'store_id' => $reviewer->id]);
    }

    $resolution = app(ProposalResolutionService::class);
    $resolution->castVote($proposal, $reviewerOne, false, null);
    $resolution->castVote($proposal->refresh(), $reviewerTwo, false, null);

    expect($proposal->refresh()->status)->toBe(Proposal::STATUS_REJECTED);

    $response = $this->actingAs($reviewerOne->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk();

    // Still one version, the one that existed before the proposal was ever made. A
    // rejected proposal writes no row here rather than being filtered out of one.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.version_number'))->toBe(1)
        ->and(ProductVersion::where('proposal_id', $proposal->id)->exists())->toBeFalse();

    // And the record itself is untouched, so no snapshot could carry the change.
    expect($product->refresh()->specifications['inputs'])->toBe('2');
});

it('adds a version when a proposal is approved, attributed to the proposing store', function (): void {
    $product = vh_product();

    $proposer = vh_store();
    $reviewer = vh_store();

    vh_carry($reviewer, $product);
    vh_firstVersion($product, $reviewer);

    $proposal = Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => ['inputs' => ['from' => '2', 'to' => '4']],
        'confidence_band' => Proposal::BAND_HIGH,
        'confidence_score' => 0.9,
        'intended_variant_ids' => [$product->variants()->first()->id],
        'intended_price_minor' => 450_000,
        'intended_currency' => 'LKR',
    ]);

    ProposalReviewer::create(['proposal_id' => $proposal->id, 'store_id' => $reviewer->id]);

    app(ProposalResolutionService::class)->castVote($proposal, $reviewer, true, null);

    expect($proposal->refresh()->status)->toBe(Proposal::STATUS_APPROVED);

    $this->actingAs($reviewer->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.version_number', 2)
        ->assertJsonPath('data.0.caused_by_store.id', $proposer->id)
        ->assertJsonPath('data.0.is_admin_originated', false)
        ->assertJsonPath('data.0.changed_fields', ['specifications']);

    $this->actingAs($reviewer->user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/versions/2")
        ->assertOk()
        ->assertJsonPath('data.snapshot.specifications.inputs', '4');
});

/*
|--------------------------------------------------------------------------
| Nothing forbidden crosses the wire
|--------------------------------------------------------------------------
*/

it('carries no confidence score, no proposal id, and no product creator', function (): void {
    $product = vh_product();
    $seller = vh_store();
    vh_carry($seller, $product);

    vh_firstVersion($product, $seller);

    foreach (["/api/products/{$product->slug}/versions", "/api/products/{$product->slug}/versions/1"] as $path) {
        $body = $this->actingAs($seller->user, 'sanctum')->getJson($path)->getContent();

        expect($body)->not->toContain('confidence_score')
            ->and($body)->not->toContain('confidence_band')
            ->and($body)->not->toContain('created_by_store_id')
            // EP-29 answers 404 to most readers of this list, so an id here would be a
            // link that mostly does not open.
            ->and($body)->not->toContain('proposal_id')
            ->and($body)->not->toContain('caused_by_user');
    }
});
