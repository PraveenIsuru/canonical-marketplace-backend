<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\CommunityPost;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Models\ProductVersion;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalogue\ProductVersionService;
use App\Services\Proposals\ProposalResolutionService;
use Illuminate\Support\Facades\Storage;

/**
 * M11 Administration (EP-40 to EP-45, EP-49, EP-58 to EP-61).
 *
 * The build plan's M11 list, item by item: both escalation outcomes unblocking the
 * proposing seller, a direct edit creating a version with the administrator flag and
 * the acting administrator recorded, an added attribute option generating combinations
 * additively while leaving existing attachments untouched, reversing an approval
 * creating a further version, and a post soft deleted rather than removed.
 *
 * Helpers are prefixed and local to this file so it runs alone.
 */
function m11_admin(): User
{
    return User::factory()->create(['is_admin' => true, 'name' => 'A. Administrator']);
}

function m11_store(string $name = 'Colombo Audio'): Store
{
    return Store::factory()->for(User::factory())->create(['name' => $name]);
}

function m11_product(): Product
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

function m11_carry(Store $store, Product $product, ?Variant $variant = null): Attachment
{
    return Attachment::create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'variant_id' => ($variant ?? $product->variants()->first())->id,
        'price_minor' => 450_000,
        'currency' => 'LKR',
        'is_available' => true,
    ]);
}

/**
 * A proposal that ran out of window without a decision, which is what blocks a seller.
 *
 * @param  array<int, Store>  $reviewers
 */
function m11_escalated(
    Product $product,
    Store $proposer,
    array $reviewers = [],
    array $changes = ['inputs' => ['from' => '2', 'to' => '4']],
): Proposal {
    $proposal = Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => $changes,
        'confidence_band' => Proposal::BAND_HIGH,
        'confidence_score' => 0.9,
        'status' => Proposal::STATUS_ESCALATED,
        'resolution_reason' => 'no_votes_cast',
        'resolved_at' => now(),
        'intended_variant_ids' => [$product->variants()->first()->id],
        'intended_price_minor' => 450_000,
        'intended_currency' => 'LKR',
    ]);

    foreach ($reviewers as $reviewer) {
        ProposalReviewer::create(['proposal_id' => $proposal->id, 'store_id' => $reviewer->id]);
    }

    return $proposal;
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses every administrator route to an anonymous caller', function (string $method, string $path): void {
    $this->json($method, $path)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
})->with([
    ['get', '/api/admin/escalations'],
    ['get', '/api/admin/proposals'],
    ['get', '/api/admin/products'],
    ['get', '/api/admin/metrics'],
]);

it('refuses every administrator route to an ordinary seller', function (): void {
    $store = m11_store();

    foreach (['/api/admin/escalations', '/api/admin/proposals', '/api/admin/products', '/api/admin/metrics'] as $path) {
        $this->actingAs($store->user, 'sanctum')
            ->getJson($path)
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }
});

/*
|--------------------------------------------------------------------------
| EP-40 and EP-58 Reading proposals
|--------------------------------------------------------------------------
*/

it('lists escalations oldest blocked first', function (): void {
    $product = m11_product();

    $recent = m11_escalated($product, m11_store('Recent'));
    $recent->forceFill(['review_opens_at' => now()->subDays(2)])->save();

    $stale = m11_escalated($product, m11_store('Stale'));
    $stale->forceFill(['review_opens_at' => now()->subDays(9)])->save();

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/escalations')
        ->assertOk()
        // The seller who has waited longest comes first. That ordering is the whole
        // purpose of the queue.
        ->assertJsonPath('data.0.id', $stale->id)
        ->assertJsonPath('data.1.id', $recent->id)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('shows the proposing store and the vote split, which reviewers never see', function (): void {
    $product = m11_product();
    $proposer = m11_store('Pettah Gadgets');
    $one = m11_store('One');
    $two = m11_store('Two');

    $proposal = m11_escalated($product, $proposer, [$one, $two]);

    app(ProposalResolutionService::class)->recordVote($proposal, $one->id, true, 'Mine says four.');
    app(ProposalResolutionService::class)->recordVote($proposal, $two->id, false, null);

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/escalations')
        ->assertOk()
        ->assertJsonPath('data.0.store.name', 'Pettah Gadgets')
        ->assertJsonPath('data.0.votes_cast', 2)
        ->assertJsonPath('data.0.votes_in_favour', 1)
        ->assertJsonPath('data.0.votes_against', 1)
        ->assertJsonPath('data.0.reviewer_count', 2)
        ->assertJsonPath('data.0.resolution_reason', 'no_votes_cast');
});

it('lists every proposal and filters by status', function (): void {
    $product = m11_product();
    m11_escalated($product, m11_store('A'));

    Proposal::factory()->for($product)->for(m11_store('B'))->create([
        'changes' => ['inputs' => ['from' => '2', 'to' => '6']],
        'confidence_band' => Proposal::BAND_LOW,
        'confidence_score' => 0.3,
        'status' => Proposal::STATUS_PENDING,
    ]);

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/proposals')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/proposals?status=escalated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'escalated');
});

it('shows the change comparison, the vote comments, and what approval would release', function (): void {
    $product = m11_product();
    $proposer = m11_store();
    $reviewer = m11_store('Reviewer');

    $proposal = m11_escalated($product, $proposer, [$reviewer]);
    app(ProposalResolutionService::class)->recordVote($proposal, $reviewer->id, true, 'Confirmed on mine.');

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson("/api/admin/proposals/{$proposal->id}")
        ->assertOk()
        ->assertJsonPath('data.changes.0.attribute', 'inputs')
        ->assertJsonPath('data.changes.0.from', '2')
        ->assertJsonPath('data.changes.0.to', '4')
        // The comments are the argument the administrator is being asked to settle.
        ->assertJsonPath('data.votes.0.vote', 'approve')
        ->assertJsonPath('data.votes.0.comment', 'Confirmed on mine.')
        ->assertJsonPath('data.votes.0.store.name', 'Reviewer')
        ->assertJsonPath('data.intended_listing.price_minor', 450_000)
        ->assertJsonPath('data.resolved_by', null);
});

/*
|--------------------------------------------------------------------------
| EP-41 Both outcomes unblock the seller
|--------------------------------------------------------------------------
*/

it('unblocks the proposing seller when an administrator approves an escalation', function (): void {
    $product = m11_product();
    $proposer = m11_store();
    $proposal = m11_escalated($product, $proposer);

    expect($proposal->isBlocking())->toBeTrue()
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(0);

    $this->actingAs(m11_admin(), 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/resolve", ['decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.seller_unblocked', true)
        ->assertJsonPath('data.attachments_created', 1)
        ->assertJsonPath('data.version_number', 1);

    expect($proposal->refresh()->isBlocking())->toBeFalse()
        // Approval releases the listing that was being withheld.
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(1)
        ->and($product->refresh()->specifications['inputs'])->toBe('4')
        ->and($proposer->refresh()->is_live)->toBeTrue();
});

it('unblocks the proposing seller when an administrator rejects an escalation', function (): void {
    $product = m11_product();
    $proposer = m11_store();
    $proposal = m11_escalated($product, $proposer);

    $this->actingAs(m11_admin(), 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/resolve", ['decision' => 'reject'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        // The point of the field. What blocked them was an unresolved proposal, not an
        // unfavourable one, so rejection releases them just as approval does.
        ->assertJsonPath('data.seller_unblocked', true)
        ->assertJsonPath('data.version_number', null)
        ->assertJsonPath('data.attachments_created', 0);

    expect($proposal->refresh()->isBlocking())->toBeFalse()
        // Neither a version nor an attachment, and the record is untouched.
        ->and(ProductVersion::where('product_id', $product->id)->count())->toBe(0)
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(0)
        ->and($product->refresh()->specifications['inputs'])->toBe('2');
});

it('records the acting administrator without naming them to sellers', function (): void {
    $product = m11_product();
    $proposal = m11_escalated($product, m11_store());
    $administrator = m11_admin();

    $this->actingAs($administrator, 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/resolve", ['decision' => 'approve'])
        ->assertOk();

    expect($proposal->refresh()->resolved_by_user_id)->toBe($administrator->id)
        // The escalation reason survives. It records *why this escalated*, which is a
        // different fact from *who settled it*.
        ->and($proposal->resolution_reason)->toBe('no_votes_cast');

    // The version is attributed to the proposing store, not to the administrator, and
    // is not flagged as an administrator edit: the change was the seller's.
    $version = ProductVersion::where('product_id', $product->id)->first();

    expect($version->is_admin_originated)->toBeFalse()
        ->and($version->caused_by_store_id)->toBe($proposal->store_id);
});

it('refuses to resolve a proposal that is not escalated', function (): void {
    $product = m11_product();

    $pending = Proposal::factory()->for($product)->for(m11_store())->create([
        'changes' => ['inputs' => ['from' => '2', 'to' => '4']],
        'confidence_band' => Proposal::BAND_HIGH,
        'confidence_score' => 0.9,
        'status' => Proposal::STATUS_PENDING,
    ]);

    $this->actingAs(m11_admin(), 'sanctum')
        ->postJson("/api/admin/proposals/{$pending->id}/resolve", ['decision' => 'approve'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'proposal_not_escalated');
});

it('refuses a decision that is neither approve nor reject', function (): void {
    $product = m11_product();
    $proposal = m11_escalated($product, m11_store());

    $this->actingAs(m11_admin(), 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/resolve", ['decision' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

/*
|--------------------------------------------------------------------------
| EP-42 Reversing a decision
|--------------------------------------------------------------------------
*/

it('creates a further version when an approval is reversed, and deletes nothing', function (): void {
    $product = m11_product();
    $proposer = m11_store();
    $proposal = m11_escalated($product, $proposer);

    $administrator = m11_admin();

    // Approve first, which applies the change and writes version 1.
    $this->actingAs($administrator, 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/resolve", ['decision' => 'approve'])
        ->assertOk();

    expect($product->refresh()->specifications['inputs'])->toBe('4');

    $this->actingAs($administrator, 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/override", ['decision' => 'reject'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.version_number', 2);

    $versions = ProductVersion::where('product_id', $product->id)->orderBy('version_number')->get();

    // Two versions, not one. The chain moves forward and the reversed version stays.
    expect($versions)->toHaveCount(2)
        ->and($versions[0]->is_admin_originated)->toBeFalse()
        ->and($versions[1]->is_admin_originated)->toBeTrue()
        // The record is back to what it said, by moving forward rather than backwards.
        ->and($product->refresh()->specifications['inputs'])->toBe('2')
        // And the seller keeps their listing. Reversing a claim about what a product is
        // says nothing about whether that shop stocks it.
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(1);
});

it('keeps attribute options and combinations when an approval is reversed', function (): void {
    $product = m11_product();
    $proposer = m11_store();

    // The proposal widens Colour, which generates a third combination on approval.
    $proposal = m11_escalated($product, $proposer, changes: [
        'Colour' => ['from' => 'Black, Grey', 'to' => 'Black, Grey, Sand'],
    ]);

    $administrator = m11_admin();

    $this->actingAs($administrator, 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/resolve", ['decision' => 'approve'])
        ->assertOk();

    expect($product->refresh()->variants()->count())->toBe(3);

    $this->actingAs($administrator, 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/override", ['decision' => 'reject'])
        ->assertOk();

    /*
     * Invariant 2. A combination is never removed, by anyone, an administrator
     * included, so the option that generated it survives the reversal too. Narrowing
     * the option would strand a combination nothing could ever clean up.
     */
    expect($product->refresh()->variants()->count())->toBe(3)
        ->and($product->productAttributes()->first()->options)->toContain('Sand');
});

it('refuses to override a proposal that nobody has decided yet', function (): void {
    $product = m11_product();
    $proposal = m11_escalated($product, m11_store());

    $this->actingAs(m11_admin(), 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/override", ['decision' => 'approve'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'proposal_not_resolved');
});

it('releases the withheld listing when a rejection is overridden into an approval', function (): void {
    $product = m11_product();
    $proposer = m11_store();
    $proposal = m11_escalated($product, $proposer);

    $administrator = m11_admin();

    $this->actingAs($administrator, 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/resolve", ['decision' => 'reject'])
        ->assertOk();

    expect(Attachment::where('store_id', $proposer->id)->count())->toBe(0);

    $this->actingAs($administrator, 'sanctum')
        ->postJson("/api/admin/proposals/{$proposal->id}/override", ['decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.attachments_created', 1);

    expect(Attachment::where('store_id', $proposer->id)->count())->toBe(1)
        ->and($product->refresh()->specifications['inputs'])->toBe('4');
});

/*
|--------------------------------------------------------------------------
| EP-43 Direct edits
|--------------------------------------------------------------------------
*/

it('creates an administrator originated version recording who acted', function (): void {
    $product = m11_product();
    $administrator = m11_admin();

    $this->actingAs($administrator, 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", ['name' => 'Aurora Field Recorder FR-2 Mk II'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Aurora Field Recorder FR-2 Mk II');

    $version = ProductVersion::where('product_id', $product->id)->first();

    expect($version->is_admin_originated)->toBeTrue()
        // The acting administrator is recorded on the row.
        ->and($version->caused_by_user_id)->toBe($administrator->id)
        // And no store caused it, because no seller did.
        ->and($version->caused_by_store_id)->toBeNull()
        ->and($version->snapshot['name'])->toBe('Aurora Field Recorder FR-2 Mk II');
});

it('adds attribute options additively and leaves existing attachments untouched', function (): void {
    $product = m11_product();
    $store = m11_store();

    $black = $product->variants()->get()->first(
        fn (Variant $variant): bool => ($variant->attribute_values['Colour'] ?? null) === 'Black',
    );

    $listing = m11_carry($store, $product, $black);

    $this->actingAs(m11_admin(), 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", [
            // Only Sand is new. Black and Grey are already there.
            'attributes' => [['name' => 'Colour', 'options' => ['Sand']]],
        ])
        ->assertOk();

    $product->refresh();

    expect($product->productAttributes()->first()->options)->toBe(['Black', 'Grey', 'Sand'])
        // Additive: the two that existed plus the one the new option makes possible.
        ->and($product->variants()->count())->toBe(3)
        // The seller carrying Black keeps carrying exactly that, at the same price.
        ->and($listing->refresh()->variant_id)->toBe($black->id)
        ->and($listing->price_minor)->toBe(450_000)
        ->and(Attachment::where('store_id', $store->id)->count())->toBe(1);
});

it('refuses to add an attribute the record does not already define', function (): void {
    $product = m11_product();

    /*
     * Adding a new dimension to a record that already has combinations would leave
     * every one of them with no value for it, permanently, since invariant 2 means
     * nothing can remove a combination afterwards.
     */
    $this->actingAs(m11_admin(), 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", [
            'attributes' => [['name' => 'Size', 'options' => ['Small', 'Large']]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['attributes']]);

    expect($product->refresh()->productAttributes()->count())->toBe(1)
        ->and($product->variants()->count())->toBe(2);
});

it('does not narrow an option list when a shorter one is sent', function (): void {
    $product = m11_product();

    $this->actingAs(m11_admin(), 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", [
            'attributes' => [['name' => 'Colour', 'options' => ['Black']]],
        ])
        ->assertOk();

    // Grey survives. Widening is the only direction available.
    expect($product->refresh()->productAttributes()->first()->options)->toBe(['Black', 'Grey']);
});

it('replaces the specification map wholesale so a key can be removed', function (): void {
    $product = m11_product();

    $this->actingAs(m11_admin(), 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", [
            'specifications' => ['inputs' => '2'],
        ])
        ->assertOk();

    // sample_rate is gone. A specification has nothing generated from it, which is why
    // it can be removed where an attribute option cannot.
    expect($product->refresh()->specifications)->toBe(['inputs' => '2']);
});

it('edits a product that has a pending proposal against it', function (): void {
    $product = m11_product();

    $pending = Proposal::factory()->for($product)->for(m11_store())->create([
        'changes' => ['inputs' => ['from' => '2', 'to' => '4']],
        'confidence_band' => Proposal::BAND_HIGH,
        'confidence_score' => 0.9,
        'status' => Proposal::STATUS_PENDING,
    ]);

    // Making an administrator wait three days for a peer review before fixing an
    // obvious error would be the wrong trade.
    $this->actingAs(m11_admin(), 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", ['category' => 'Recording'])
        ->assertOk()
        ->assertJsonPath('data.category', 'Recording')
        ->assertJsonPath('data.has_pending_proposal', true);

    // And the proposal is undisturbed.
    expect($pending->refresh()->status)->toBe(Proposal::STATUS_PENDING);
});

it('refuses an empty edit rather than writing a version recording nothing', function (): void {
    $product = m11_product();

    $this->actingAs(m11_admin(), 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect(ProductVersion::where('product_id', $product->id)->count())->toBe(0);
});

it('does not accept a slug or a variants array', function (): void {
    $product = m11_product();
    $original = $product->slug;

    $this->actingAs(m11_admin(), 'sanctum')
        ->patchJson("/api/admin/products/{$product->id}", [
            'name' => 'Renamed',
            'slug' => 'something-else',
            'variants' => [],
        ])
        ->assertOk();

    // The public address is unchanged, and no variant was touched.
    expect($product->refresh()->slug)->toBe($original)
        ->and($product->variants()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| EP-60 and EP-61 The administrator catalogue
|--------------------------------------------------------------------------
*/

it('lists products with the counts that say whether a record is healthy', function (): void {
    $product = m11_product();
    $store = m11_store();
    m11_carry($store, $product);

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/products')
        ->assertOk()
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.seller_count', 1)
        ->assertJsonPath('data.0.variant_count', 2)
        ->assertJsonPath('data.0.has_pending_proposal', false)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('finds a product by name', function (): void {
    m11_product();
    Product::factory()->create(['name' => 'Something Else Entirely', 'category' => 'Home']);

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/products?q=aurora')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Aurora Field Recorder FR-2');
});

it('returns every generated combination including ones no seller carries', function (): void {
    $product = m11_product();
    $store = m11_store();

    $black = $product->variants()->get()->first(
        fn (Variant $variant): bool => ($variant->attribute_values['Colour'] ?? null) === 'Black',
    );

    m11_carry($store, $product, $black);

    $response = $this->actingAs(m11_admin(), 'sanctum')
        ->getJson("/api/admin/products/{$product->id}")
        ->assertOk()
        // Both combinations, not just the carried one. Hiding the empty one would be
        // the first place somebody got the idea a combination can be removed.
        ->assertJsonCount(2, 'data.variants');

    $counts = collect($response->json('data.variants'))->pluck('seller_count', 'id');

    expect($counts[$black->id])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| EP-44 Moderation
|--------------------------------------------------------------------------
*/

it('soft deletes a post rather than removing it, and hides its replies', function (): void {
    $product = m11_product();
    $author = User::factory()->create();

    $post = CommunityPost::create([
        'product_id' => $product->id,
        'user_id' => $author->id,
        'body' => 'The top level post.',
    ]);

    CommunityPost::create([
        'product_id' => $product->id,
        'user_id' => $author->id,
        'parent_id' => $post->id,
        'body' => 'A reply.',
    ]);

    $this->actingAs(m11_admin(), 'sanctum')
        ->deleteJson("/api/admin/community/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.replies_hidden', 1);

    // The row survives. Soft deleted, never destroyed.
    expect(CommunityPost::withTrashed()->find($post->id))->not->toBeNull()
        ->and(CommunityPost::find($post->id))->toBeNull();

    // And it is gone from the public thread, with no tombstone left behind.
    $this->getJson("/api/products/{$product->slug}/community/posts")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Its replies go with it, which is what the parent lookup on EP-57 enforces.
    $this->getJson("/api/products/{$product->slug}/community/posts/{$post->id}/replies")
        ->assertNotFound();
});

it('reports no hidden replies when the post removed is itself a reply', function (): void {
    $product = m11_product();
    $author = User::factory()->create();

    $post = CommunityPost::create([
        'product_id' => $product->id,
        'user_id' => $author->id,
        'body' => 'The top level post.',
    ]);

    $reply = CommunityPost::create([
        'product_id' => $product->id,
        'user_id' => $author->id,
        'parent_id' => $post->id,
        'body' => 'A reply.',
    ]);

    $this->actingAs(m11_admin(), 'sanctum')
        ->deleteJson("/api/admin/community/posts/{$reply->id}")
        ->assertOk()
        ->assertJsonPath('data.replies_hidden', 0);

    // The parent survives and its thread is still readable.
    $this->getJson("/api/products/{$product->slug}/community/posts/{$post->id}/replies")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
|--------------------------------------------------------------------------
| EP-49 Image removal
|--------------------------------------------------------------------------
*/

it('removes an image, row and file, and reports what is left', function (): void {
    Storage::fake('product_images');

    $product = m11_product();

    $image = ProductImage::factory()->for($product)->create([
        'storage_path' => 'products/1/keep-me.jpg',
        'position' => 0,
    ]);

    ProductImage::factory()->for($product)->create(['position' => 1]);

    Storage::disk('product_images')->put('products/1/keep-me.jpg', 'bytes');

    $this->actingAs(m11_admin(), 'sanctum')
        ->deleteJson("/api/products/{$product->slug}/images/{$image->id}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.images_remaining', 1);

    // Destroyed rather than soft deleted, unlike a post: an image is not evidence of
    // anything and keeping a moderated one on disk serves nobody.
    expect(ProductImage::find($image->id))->toBeNull();

    Storage::disk('product_images')->assertMissing('products/1/keep-me.jpg');
});

it('refuses to delete an image belonging to a different product', function (): void {
    $product = m11_product();
    $other = Product::factory()->create(['name' => 'Other', 'category' => 'Home']);

    $image = ProductImage::factory()->for($other)->create();

    $this->actingAs(m11_admin(), 'sanctum')
        ->deleteJson("/api/products/{$product->slug}/images/{$image->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');

    expect(ProductImage::find($image->id))->not->toBeNull();
});

it('refuses image deletion to a seller', function (): void {
    $product = m11_product();
    $store = m11_store();
    $image = ProductImage::factory()->for($product)->create();

    // A seller may add an image through EP-48 and may never remove one.
    $this->actingAs($store->user, 'sanctum')
        ->deleteJson("/api/products/{$product->slug}/images/{$image->id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

/*
|--------------------------------------------------------------------------
| EP-45 Metrics
|--------------------------------------------------------------------------
*/

it('reports the platform snapshot with the oldest escalation named', function (): void {
    $product = m11_product();
    $store = m11_store();
    m11_carry($store, $product);

    $proposal = m11_escalated($product, m11_store('Blocked'));
    $proposal->forceFill(['review_opens_at' => now()->subDays(9)])->save();

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/metrics')
        ->assertOk()
        ->assertJsonPath('data.products.total', 1)
        ->assertJsonPath('data.products.with_sellers', 1)
        ->assertJsonPath('data.products.without_sellers', 0)
        ->assertJsonPath('data.proposals.escalated', 1)
        ->assertJsonPath('data.stores.live', 1)
        ->assertJsonStructure([
            'data' => [
                'products', 'stores', 'proposals', 'community', 'views',
                'oldest_escalation_opened_at',
            ],
        ]);
});

it('reports a null oldest escalation when nothing is escalated', function (): void {
    m11_product();

    $this->actingAs(m11_admin(), 'sanctum')
        ->getJson('/api/admin/metrics')
        ->assertOk()
        // Null rather than absent. While it is set, a seller is blocked and waiting.
        ->assertJsonPath('data.oldest_escalation_opened_at', null)
        ->assertJsonPath('data.proposals.escalated', 0);
});

/*
|--------------------------------------------------------------------------
| Nothing forbidden crosses the wire, administrators included
|--------------------------------------------------------------------------
*/

it('never sends a confidence score to an administrator', function (): void {
    $product = m11_product();
    $proposer = m11_store();
    $reviewer = m11_store('Reviewer');
    $proposal = m11_escalated($product, $proposer, [$reviewer]);

    app(ProposalResolutionService::class)->recordVote($proposal, $reviewer->id, true, 'A comment.');

    app(ProductVersionService::class)->record($product);

    $administrator = m11_admin();

    $paths = [
        '/api/admin/escalations',
        '/api/admin/proposals',
        "/api/admin/proposals/{$proposal->id}",
        '/api/admin/products',
        "/api/admin/products/{$product->id}",
        '/api/admin/metrics',
    ];

    foreach ($paths as $path) {
        $body = $this->actingAs($administrator, 'sanctum')->getJson($path)->getContent();

        /*
         * Section 6 has no exceptions and an administrator is not one. An administrator
         * deciding a disagreement between a seller and the incumbents should decide on
         * the evidence, and the AI's number would anchor that exactly as it would
         * anchor a reviewer's vote.
         */
        expect($body)->not->toContain('confidence_score')
            ->and($body)->not->toContain('confidence_band')
            ->and($body)->not->toContain('created_by_store_id');
    }
});
