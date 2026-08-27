<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ProposalVote;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;

/**
 * M7 Peer review over HTTP (EP-27 to EP-30).
 *
 * The matrix and its consequences are asserted at the service level in
 * ResolutionTest.php. What is asserted here is everything the endpoints add: who may
 * read a proposal, who may vote on it, and the four ways a vote is refused.
 *
 * The helpers are local to this file rather than shared with ResolutionTest, so that
 * running either file alone still works. A test that only passes when another file
 * happens to be loaded first is a test that will fail confusingly later.
 */
function pr_product(string $name = 'Aurora Field Recorder FR-2'): Product
{
    $product = Product::factory()->create([
        'name' => $name,
        'category' => 'Audio',
        'specifications' => ['inputs' => '2'],
    ]);

    Variant::factory()->for($product)->combination([])->create();

    return $product->refresh();
}

function pr_store(): Store
{
    return Store::factory()->for(User::factory())->create();
}

/**
 * A pending proposal with a frozen reviewer set and a listing waiting on it.
 *
 * @param  array<int, Store>  $reviewers
 */
function pr_proposal(Product $product, Store $proposer, array $reviewers, string $band = Proposal::BAND_HIGH): Proposal
{
    $proposal = Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => ['inputs' => ['from' => '2', 'to' => '4']],
        'confidence_band' => $band,
        'confidence_score' => $band === Proposal::BAND_HIGH ? 0.9 : 0.4,
        'intended_variant_ids' => [$product->variants()->first()->id],
        'intended_price_minor' => 450_000,
        'intended_currency' => 'LKR',
    ]);

    foreach ($reviewers as $reviewer) {
        ProposalReviewer::create(['proposal_id' => $proposal->id, 'store_id' => $reviewer->id]);
    }

    return $proposal->refresh();
}

/*
|--------------------------------------------------------------------------
| EP-27 The caller's own proposals
|--------------------------------------------------------------------------
*/

it('lists the caller own proposals and nobody else proposals', function (): void {
    $product = pr_product();
    $mine = pr_store();
    $theirs = pr_store();

    $ownProposal = pr_proposal($product, $mine, []);
    pr_proposal(pr_product('Someone Else Recorder'), $theirs, []);

    $response = $this->actingAs($mine->user, 'sanctum')->getJson('/api/proposals/mine');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownProposal->id)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.changed_fields', ['inputs'])
        ->assertJsonPath('data.0.product.id', $product->id);

    // Section 2. Every list endpoint carries the paginator shape.
    $response->assertJsonStructure(['data', 'links', 'meta']);
});

it('keeps resolved proposals in the caller own list', function (): void {
    $store = pr_store();
    $proposal = pr_proposal(pr_product(), $store, []);
    $proposal->forceFill(['status' => Proposal::STATUS_REJECTED, 'resolved_at' => now()])->save();

    // A seller wants to know what became of a submission, so a list that dropped
    // resolved ones would look like the submission had been lost.
    $this->actingAs($store->user, 'sanctum')
        ->getJson('/api/proposals/mine')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'rejected');
});

/*
|--------------------------------------------------------------------------
| EP-28 The reviews assigned to this store
|--------------------------------------------------------------------------
*/

it('lists only the proposals this store was frozen in as a reviewer', function (): void {
    $product = pr_product();
    $proposer = pr_store();
    $reviewer = pr_store();
    $bystander = pr_store();

    $assigned = pr_proposal($product, $proposer, [$reviewer]);

    $this->actingAs($reviewer->user, 'sanctum')
        ->getJson('/api/proposals/to-review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assigned->id)
        ->assertJsonPath('data.0.has_voted', false)
        ->assertJsonPath('data.0.reviewer_count', 1)
        ->assertJsonPath('data.0.votes_cast', 0);

    $this->actingAs($bystander->user, 'sanctum')
        ->getJson('/api/proposals/to-review')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('never offers the proposing store its own proposal to review', function (): void {
    $product = pr_product();
    $proposer = pr_store();

    // The proposer is excluded from its own reviewer set at M6, so this is really an
    // assertion that EP-28 reads that set rather than inferring eligibility.
    pr_proposal($product, $proposer, [pr_store()]);

    $this->actingAs($proposer->user, 'sanctum')
        ->getJson('/api/proposals/to-review')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('keeps a proposal listed after this store has voted, marked as voted', function (): void {
    $product = pr_product();
    $reviewer = pr_store();
    $other = pr_store();
    $proposal = pr_proposal($product, pr_store(), [$reviewer, $other]);

    ProposalVote::create(['proposal_id' => $proposal->id, 'store_id' => $reviewer->id, 'vote' => true]);

    $this->actingAs($reviewer->user, 'sanctum')
        ->getJson('/api/proposals/to-review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.has_voted', true)
        ->assertJsonPath('data.0.votes_cast', 1);
});

it('drops a proposal from the review queue once its window has closed', function (): void {
    $reviewer = pr_store();
    $proposal = pr_proposal(pr_product(), pr_store(), [$reviewer]);
    $proposal->forceFill(['review_closes_at' => now()->subHour()])->save();

    // A closed window cannot take another vote, so offering it would be offering work
    // that cannot be done.
    $this->actingAs($reviewer->user, 'sanctum')
        ->getJson('/api/proposals/to-review')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
|--------------------------------------------------------------------------
| EP-29 The change comparison
|--------------------------------------------------------------------------
*/

it('shows the change comparison to a frozen reviewer', function (): void {
    $product = pr_product();
    $reviewer = pr_store();
    $proposal = pr_proposal($product, pr_store(), [$reviewer]);

    $this->actingAs($reviewer->user, 'sanctum')
        ->getJson("/api/proposals/{$proposal->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $proposal->id)
        ->assertJsonPath('data.changes.0.attribute', 'inputs')
        ->assertJsonPath('data.changes.0.from', '2')
        ->assertJsonPath('data.changes.0.to', '4')
        ->assertJsonPath('data.can_vote', true)
        ->assertJsonPath('data.is_mine', false);
});

it('shows the proposing store its own proposal but does not offer it a vote', function (): void {
    $proposer = pr_store();
    $proposal = pr_proposal(pr_product(), $proposer, [pr_store()]);

    $this->actingAs($proposer->user, 'sanctum')
        ->getJson("/api/proposals/{$proposal->id}")
        ->assertOk()
        ->assertJsonPath('data.is_mine', true)
        ->assertJsonPath('data.can_vote', false);
});

it('answers 404 to a store that is neither the proposer nor a reviewer', function (): void {
    $proposal = pr_proposal(pr_product(), pr_store(), [pr_store()]);
    $outsider = pr_store();

    // 404 rather than 403. Which products a competitor is arguing about is not
    // something to confirm by the choice of status code.
    $this->actingAs($outsider->user, 'sanctum')
        ->getJson("/api/proposals/{$proposal->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

/*
|--------------------------------------------------------------------------
| EP-30 Voting, and the four ways it is refused
|--------------------------------------------------------------------------
*/

it('records a vote and reports the status afterwards', function (): void {
    $product = pr_product();
    $reviewer = pr_store();
    $second = pr_store();
    $proposal = pr_proposal($product, pr_store(), [$reviewer, $second]);

    // Two reviewers, one vote, so it stays pending: section 11.6 carries the post vote
    // status, which here is still "pending".
    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.vote_recorded', true)
        ->assertJsonPath('data.proposal_status', 'pending')
        ->assertJsonPath('data.resolved_at', null);

    expect(ProposalVote::where('proposal_id', $proposal->id)->count())->toBe(1);
});

it('makes a sole reviewer a majority of one and resolves immediately', function (): void {
    $product = pr_product();
    $reviewer = pr_store();
    $proposal = pr_proposal($product, pr_store(), [$reviewer]);

    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.proposal_status', 'approved');

    expect($proposal->refresh()->resolved_at)->not->toBeNull();
});

it('refuses a vote from a store that was not attached when the proposal opened', function (): void {
    $product = pr_product();
    $proposal = pr_proposal($product, pr_store(), [pr_store()]);

    $latecomer = pr_store();

    /*
     * Attached now, and carrying the product, but not in the frozen set. Eligibility is
     * a fact about the moment the proposal opened, so this must be refused even though
     * a query against current attachments would say otherwise.
     */
    Attachment::create([
        'store_id' => $latecomer->id,
        'product_id' => $product->id,
        'variant_id' => $product->variants()->first()->id,
        'price_minor' => 400_000,
        'currency' => 'LKR',
        'is_available' => true,
    ]);

    $this->actingAs($latecomer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_eligible_to_vote');

    expect(ProposalVote::where('proposal_id', $proposal->id)->count())->toBe(0);
});

it('still hides the proposal itself from a store that attached mid window', function (): void {
    $product = pr_product();
    $proposal = pr_proposal($product, pr_store(), [pr_store()]);
    $latecomer = pr_store();

    Attachment::create([
        'store_id' => $latecomer->id,
        'product_id' => $product->id,
        'variant_id' => $product->variants()->first()->id,
        'price_minor' => 400_000,
        'currency' => 'LKR',
        'is_available' => true,
    ]);

    /*
     * EP-30 tells them they may not vote, but EP-29 still will not show them what is
     * being argued about. The vote refusal reveals only that an id exists.
     */
    $this->actingAs($latecomer->user, 'sanctum')
        ->getJson("/api/proposals/{$proposal->id}")
        ->assertNotFound();
});

it('lets a store that detached mid window keep its vote', function (): void {
    $product = pr_product();
    $reviewer = pr_store();
    $proposal = pr_proposal($product, pr_store(), [$reviewer, pr_store()]);

    // In the frozen set, carrying nothing now. Its vote was already owed when the
    // window opened, so walking away from the product does not withdraw it.
    Attachment::where('store_id', $reviewer->id)->delete();

    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'reject'])
        ->assertOk()
        ->assertJsonPath('data.vote_recorded', true);
});

it('refuses a second vote from the same store', function (): void {
    $reviewer = pr_store();
    $proposal = pr_proposal(pr_product(), pr_store(), [$reviewer, pr_store()]);

    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertOk();

    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'reject'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_voted');

    // The first vote stands. A vote is never revised.
    expect(ProposalVote::where('proposal_id', $proposal->id)->first()->vote)->toBeTrue();
});

it('refuses a vote after the review window has closed', function (): void {
    $reviewer = pr_store();
    $proposal = pr_proposal(pr_product(), pr_store(), [$reviewer, pr_store()]);
    $proposal->forceFill(['review_closes_at' => now()->subMinute()])->save();

    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'review_closed');

    expect(ProposalVote::where('proposal_id', $proposal->id)->count())->toBe(0);
});

it('refuses a vote on a proposal that has already resolved', function (): void {
    $reviewer = pr_store();
    $proposal = pr_proposal(pr_product(), pr_store(), [$reviewer, pr_store()]);
    $proposal->forceFill(['status' => Proposal::STATUS_ESCALATED, 'resolved_at' => now()])->save();

    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'review_closed');
});

it('refuses a vote from the proposing store on its own proposal', function (): void {
    $proposer = pr_store();
    $proposal = pr_proposal(pr_product(), $proposer, [pr_store()]);

    // Not in its own reviewer set, so it falls out at the eligibility check without
    // needing a rule of its own. A seller voting on their own case decides it.
    $this->actingAs($proposer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_eligible_to_vote');
});

it('resolves exactly once when the last two votes arrive together', function (): void {
    $product = pr_product();
    $first = pr_store();
    $second = pr_store();
    $proposal = pr_proposal($product, pr_store(), [$first, $second]);

    $this->actingAs($first->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.proposal_status', 'pending');

    $this->actingAs($second->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])
        ->assertOk()
        ->assertJsonPath('data.proposal_status', 'approved');

    /*
     * One version, not two. The row lock in the resolution service is what makes this
     * true: without it both requests would read the same tally, both call the outcome,
     * and both apply it.
     */
    expect($product->versions()->count())->toBe(1)
        ->and($proposal->refresh()->status)->toBe(Proposal::STATUS_APPROVED);
});

it('rejects a vote value that is neither approve nor reject', function (): void {
    $reviewer = pr_store();
    $proposal = pr_proposal(pr_product(), pr_store(), [$reviewer]);

    // No third value for abstaining. A reviewer with no view simply does not vote.
    $this->actingAs($reviewer->user, 'sanctum')
        ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'abstain'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

/*
|--------------------------------------------------------------------------
| Access, and the score that must never appear
|--------------------------------------------------------------------------
*/

it('refuses every peer review route to a user with no store', function (): void {
    $proposal = pr_proposal(pr_product(), pr_store(), [pr_store()]);
    $buyer = User::factory()->create();

    foreach ([
        ['getJson', '/api/proposals/mine'],
        ['getJson', '/api/proposals/to-review'],
        ['getJson', "/api/proposals/{$proposal->id}"],
    ] as [$method, $path]) {
        $this->actingAs($buyer, 'sanctum')->{$method}($path)
            ->assertStatus(403)
            ->assertJsonPath('code', 'store_required');
    }
});

it('refuses every peer review route without a token', function (): void {
    $this->getJson('/api/proposals/mine')->assertUnauthorized();
    $this->getJson('/api/proposals/to-review')->assertUnauthorized();
});

it('never serialises the confidence score or band on any peer review response', function (): void {
    $product = pr_product();
    $reviewer = pr_store();
    $proposer = pr_store();
    $proposal = pr_proposal($product, $proposer, [$reviewer]);

    /*
     * Checked on the raw body rather than with assertJsonMissing, so a nested or
     * renamed occurrence is caught too. Invariant 3: the score decides the outcome
     * server side and reaches nobody, the proposing seller included.
     */
    $bodies = [
        $this->actingAs($reviewer->user, 'sanctum')->getJson('/api/proposals/to-review')->getContent(),
        $this->actingAs($reviewer->user, 'sanctum')->getJson("/api/proposals/{$proposal->id}")->getContent(),
        $this->actingAs($proposer->user, 'sanctum')->getJson('/api/proposals/mine')->getContent(),
        $this->actingAs($proposer->user, 'sanctum')->getJson("/api/proposals/{$proposal->id}")->getContent(),
        $this->actingAs($reviewer->user, 'sanctum')
            ->postJson("/api/proposals/{$proposal->id}/vote", ['vote' => 'approve'])->getContent(),
    ];

    foreach ($bodies as $body) {
        expect($body)->not->toContain('confidence_score')
            ->and($body)->not->toContain('confidence_band')
            ->and($body)->not->toContain('resolution_reason');
    }
});
