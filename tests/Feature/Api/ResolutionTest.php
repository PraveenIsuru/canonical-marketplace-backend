<?php

declare(strict_types=1);

use App\Models\Attachment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVersion;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ProposalVote;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Proposals\ProposalResolutionService;
use App\Services\Proposals\ResolutionMatrix;
use App\Services\Proposals\ResolutionOutcome;

/**
 * M7 Peer review and resolution, at the decision level.
 *
 * The matrix is a pure function, so every row of it is asserted directly rather than
 * through a proposal. Its consequences, meaning versions, attachments, and the
 * proposing seller finally being unblocked, are asserted against the service.
 *
 * The endpoint tests live in PeerReviewTest.php.
 */

function matrix(): ResolutionMatrix
{
    return app(ResolutionMatrix::class);
}

function resolution(): ProposalResolutionService
{
    return app(ProposalResolutionService::class);
}

function reviewProduct(): Product
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

function reviewStore(): Store
{
    return Store::factory()->for(User::factory())->create();
}

/**
 * A pending proposal with its reviewer set frozen, and a listing waiting on it.
 *
 * @param  array<int, Store>  $reviewers
 */
function pendingProposal(Product $product, Store $proposer, array $reviewers, string $band = Proposal::BAND_HIGH, array $changes = ['inputs' => ['from' => '2', 'to' => '4']]): Proposal
{
    $proposal = Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => $changes,
        'confidence_band' => $band,
        'confidence_score' => $band === Proposal::BAND_HIGH ? 0.9 : 0.4,
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
| The four matrix rows, plus the two the table does not have
|--------------------------------------------------------------------------
*/

it('approves high confidence with peers in favour', function (): void {
    $outcome = matrix()->decide(Proposal::BAND_HIGH, inFavour: 2, against: 1);

    expect($outcome->status)->toBe(Proposal::STATUS_APPROVED)
        ->and($outcome->reason)->toBe(ResolutionOutcome::REASON_HIGH_FAVOUR);
});

it('escalates high confidence with peers against', function (): void {
    /*
     * The row worth explaining. It escalates rather than rejecting because it is real
     * disagreement between a well evidenced submission and the incumbents, and
     * rejecting automatically would discard a correct amendment from a seller who
     * knows the product better than the people already listing it.
     */
    $outcome = matrix()->decide(Proposal::BAND_HIGH, inFavour: 1, against: 3);

    expect($outcome->status)->toBe(Proposal::STATUS_ESCALATED)
        ->and($outcome->reason)->toBe(ResolutionOutcome::REASON_HIGH_AGAINST);
});

it('approves low confidence with peers in favour', function (): void {
    $outcome = matrix()->decide(Proposal::BAND_LOW, inFavour: 3, against: 0);

    expect($outcome->status)->toBe(Proposal::STATUS_APPROVED)
        ->and($outcome->reason)->toBe(ResolutionOutcome::REASON_LOW_FAVOUR);
});

it('rejects low confidence with peers against', function (): void {
    $outcome = matrix()->decide(Proposal::BAND_LOW, inFavour: 0, against: 2);

    expect($outcome->status)->toBe(Proposal::STATUS_REJECTED)
        ->and($outcome->reason)->toBe(ResolutionOutcome::REASON_LOW_AGAINST);
});

it('escalates a tie rather than picking a side', function (): void {
    foreach ([Proposal::BAND_HIGH, Proposal::BAND_LOW] as $band) {
        $outcome = matrix()->decide($band, inFavour: 2, against: 2);

        // Neither matrix row applies, and defaulting would mean choosing the side the
        // reviewers deliberately did not choose.
        expect($outcome->status)->toBe(Proposal::STATUS_ESCALATED)
            ->and($outcome->reason)->toBe(ResolutionOutcome::REASON_TIE);
    }
});

it('escalates when nobody voted, whatever the confidence', function (): void {
    foreach ([Proposal::BAND_HIGH, Proposal::BAND_LOW] as $band) {
        $outcome = matrix()->decide($band, inFavour: 0, against: 0);

        expect($outcome->status)->toBe(Proposal::STATUS_ESCALATED)
            ->and($outcome->reason)->toBe(ResolutionOutcome::REASON_NO_VOTES);
    }
});

it('counts a majority of votes cast, not of eligible reviewers', function (): void {
    /*
     * Five reviewers, three silent. Two in favour and none against is a majority in
     * favour, not two out of five. Counting silence as opposition would let a proposal
     * fail because people were busy.
     */
    $outcome = matrix()->decide(Proposal::BAND_LOW, inFavour: 2, against: 0);

    expect($outcome->status)->toBe(Proposal::STATUS_APPROVED);
});

/*
|--------------------------------------------------------------------------
| What an approval actually does
|--------------------------------------------------------------------------
*/

it('applies the change, writes a version, and releases the withheld attachment', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $reviewer = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$reviewer]);

    // While pending there is no attachment, and that absence is the block.
    expect(Attachment::where('store_id', $proposer->id)->count())->toBe(0);

    resolution()->recordVote($proposal, $reviewer->id, inFavour: true, comment: null);

    $resolved = $proposal->refresh();

    expect($resolved->status)->toBe(Proposal::STATUS_APPROVED)
        ->and($resolved->resolved_at)->not->toBeNull()
        // The change is now on the record.
        ->and($product->refresh()->specifications['inputs'])->toBe('4');

    // A version exists for an accepted proposal and for nothing else.
    $version = ProductVersion::where('product_id', $product->id)->latest('version_number')->first();

    expect($version)->not->toBeNull()
        ->and($version->proposal_id)->toBe($resolved->id)
        ->and($version->caused_by_store_id)->toBe($proposer->id)
        ->and($version->is_admin_originated)->toBeFalse();

    /*
     * The point of the whole milestone. The attachment withheld since M6 is created,
     * the seller is listed, and the store becomes visible.
     */
    expect(Attachment::where('store_id', $proposer->id)->count())->toBe(1)
        ->and(Attachment::where('store_id', $proposer->id)->first()->price_minor)->toBe(450_000)
        ->and($proposer->refresh()->is_live)->toBeTrue();
});

it('creates neither a version nor an attachment on rejection', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $reviewer = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$reviewer], Proposal::BAND_LOW);

    $before = ProductVersion::where('product_id', $product->id)->count();

    resolution()->recordVote($proposal, $reviewer->id, inFavour: false, comment: 'Mine has two.');

    expect($proposal->refresh()->status)->toBe(Proposal::STATUS_REJECTED)
        ->and(ProductVersion::where('product_id', $product->id)->count())->toBe($before)
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(0)
        // The record is untouched.
        ->and($product->refresh()->specifications['inputs'])->toBe('2')
        ->and($proposer->refresh()->is_live)->toBeFalse();
});

it('leaves an escalated proposal blocking its seller', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $reviewer = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$reviewer], Proposal::BAND_HIGH);

    resolution()->recordVote($proposal, $reviewer->id, inFavour: false, comment: null);

    $resolved = $proposal->refresh();

    // Waiting on an administrator, so still unresolved as far as the seller is
    // concerned, and still blocking.
    expect($resolved->status)->toBe(Proposal::STATUS_ESCALATED)
        ->and($resolved->isBlocking())->toBeTrue()
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(0);
});

it('makes a sole reviewer a majority of one', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $sole = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$sole]);

    resolution()->recordVote($proposal, $sole->id, inFavour: true, comment: null);

    // Resolved the moment they voted, rather than waiting out three days for an
    // answer that cannot change.
    expect($proposal->refresh()->status)->toBe(Proposal::STATUS_APPROVED);
});

it('stays pending while a reviewer has not voted', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $first = reviewStore();
    $second = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$first, $second]);

    resolution()->recordVote($proposal, $first->id, inFavour: true, comment: null);

    expect($proposal->refresh()->status)->toBe(Proposal::STATUS_PENDING)
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The sweep
|--------------------------------------------------------------------------
*/

it('escalates an expired proposal that nobody voted on', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $reviewer = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$reviewer]);
    $proposal->forceFill(['review_closes_at' => now()->subHour()])->save();

    $this->artisan('proposals:sweep')->assertSuccessful();

    $resolved = $proposal->refresh();

    expect($resolved->status)->toBe(Proposal::STATUS_ESCALATED)
        ->and($resolved->resolution_reason)->toBe(ResolutionOutcome::REASON_NO_VOTES);
});

it('resolves an expired proposal on the votes that were cast', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $voted = reviewStore();
    $silent = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$voted, $silent]);

    ProposalVote::create([
        'proposal_id' => $proposal->id, 'store_id' => $voted->id, 'vote' => true, 'comment' => null,
    ]);

    $proposal->forceFill(['review_closes_at' => now()->subHour()])->save();

    $this->artisan('proposals:sweep')->assertSuccessful();

    // The silent reviewer is not in the denominator, so one vote in favour carries it.
    expect($proposal->refresh()->status)->toBe(Proposal::STATUS_APPROVED)
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(1);
});

it('leaves a proposal whose window is still open alone', function (): void {
    $product = reviewProduct();
    $proposal = pendingProposal($product, reviewStore(), [reviewStore()]);

    $this->artisan('proposals:sweep')->assertSuccessful();

    expect($proposal->refresh()->status)->toBe(Proposal::STATUS_PENDING);
});

it('resolves a proposal exactly once even when the sweep runs twice', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $reviewer = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$reviewer]);
    ProposalVote::create([
        'proposal_id' => $proposal->id, 'store_id' => $reviewer->id, 'vote' => true, 'comment' => null,
    ]);
    $proposal->forceFill(['review_closes_at' => now()->subHour()])->save();

    $this->artisan('proposals:sweep')->assertSuccessful();
    $this->artisan('proposals:sweep')->assertSuccessful();

    /*
     * A second pass must find it already resolved and do nothing. Two versions of the
     * same change, or two attachments, would be the visible symptom of the guard
     * failing.
     */
    expect(ProductVersion::where('product_id', $product->id)->count())->toBe(1)
        ->and(Attachment::where('store_id', $proposer->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Applying a change to a variant attribute
|--------------------------------------------------------------------------
*/

it('adds a proposed attribute option and generates the new combinations additively', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $reviewer = reviewStore();

    $before = $product->variants()->count();

    $proposal = pendingProposal($product, $proposer, [$reviewer], Proposal::BAND_HIGH, [
        'Colour' => ['from' => 'Black, Grey', 'to' => 'Black, Grey, Sand'],
    ]);

    resolution()->recordVote($proposal, $reviewer->id, inFavour: true, comment: null);

    expect($product->refresh()->productAttributes()->first()->options)
        ->toBe(['Black', 'Grey', 'Sand'])
        // One new combination, and the two that existed are untouched.
        ->and($product->variants()->count())->toBe($before + 1);
});

it('keeps an option the proposal omitted rather than removing it', function (): void {
    $product = reviewProduct();
    $proposer = reviewStore();
    $reviewer = reviewStore();

    $proposal = pendingProposal($product, $proposer, [$reviewer], Proposal::BAND_HIGH, [
        'Colour' => ['from' => 'Black, Grey', 'to' => 'Black, Sand'],
    ]);

    resolution()->recordVote($proposal, $reviewer->id, inFavour: true, comment: null);

    /*
     * Grey stays. A combination generated from an option is permanent, so dropping the
     * option would leave combinations referring to a value the record no longer claims
     * to have. The seller told us about a version we did not know about, not that the
     * one we knew about stopped existing.
     */
    expect($product->refresh()->productAttributes()->first()->options)
        ->toBe(['Black', 'Grey', 'Sand']);
});
