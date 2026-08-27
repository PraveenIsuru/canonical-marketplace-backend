<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Models\Attachment;
use App\Models\AttachSession;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\ProposalNeedsReview;
use App\Services\Attach\ConfirmationOutcome;
use App\Services\Attach\ConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * M6 confirmation, at the service level.
 *
 * These are the milestone's load bearing rules, asserted against the service directly
 * rather than through HTTP, so a failure points at the decision rather than at the
 * controller wiring around it. The endpoint tests live in ConfirmationTest.php.
 */

/** A product with attributes and specifications, so the questions cover something. */
function confirmableProduct(array $overrides = []): Product
{
    $product = Product::factory()->create(array_merge([
        'name' => 'Aurora Field Recorder FR-2',
        'category' => 'Audio',
        'description' => 'A two channel portable recorder.',
        'specifications' => ['inputs' => '2', 'sample_rate' => '192 kHz'],
    ], $overrides));

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

function sellerStore(): Store
{
    return Store::factory()->for(User::factory())->create();
}

/** Answers that agree with the record on every question the session asked. */
function agreeingAnswers(AttachSession $session): array
{
    $answers = [];

    foreach ($session->questions as $question) {
        $answers[$question['id']] = (string) ($question['current_value'] ?? 'unchanged');
    }

    return $answers;
}

function service(): ConfirmationService
{
    return app(ConfirmationService::class);
}

/*
|--------------------------------------------------------------------------
| Every attribute is questioned, every time
|--------------------------------------------------------------------------
*/

it('asks about every field on the record without exception', function (): void {
    $product = confirmableProduct();

    $session = service()->start(sellerStore(), $product);

    $asked = collect($session->questions)->pluck('attribute')->all();

    // Core fields, every specification key, and every variant attribute. An attribute
    // nobody is asked about can never be corrected, so the record would drift exactly
    // where no one is looking.
    expect($asked)->toContain('name', 'category', 'description', 'inputs', 'sample_rate', 'Colour');
});

/*
|--------------------------------------------------------------------------
| Completion is mandatory
|--------------------------------------------------------------------------
*/

it('refuses with confirmation_incomplete when a question is unanswered', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    $answers = agreeingAnswers($session);
    $answers[array_key_first($answers)] = '   ';

    $variant = $product->variants()->first();

    expect(fn () => service()->submit($store, $session, $answers, [$variant->id], 450_000, 'LKR'))
        ->toThrow(
            fn (ApiException $e) => expect($e->errorCode())->toBe('confirmation_incomplete')
                ->and($e->status())->toBe(422),
        );

    // Nothing was written, and the session survives so the seller can finish it.
    expect(Attachment::count())->toBe(0)
        ->and(Proposal::count())->toBe(0)
        ->and(AttachSession::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The two outcomes
|--------------------------------------------------------------------------
*/

it('attaches immediately when the answers match the record', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    $variant = $product->variants()->first();

    $outcome = service()->submit($store, $session, agreeingAnswers($session), [$variant->id], 450_000, 'LKR');

    expect($outcome->outcome)->toBe(ConfirmationOutcome::ATTACHED)
        ->and($outcome->proposal)->toBeNull()
        ->and($outcome->attachments)->toHaveCount(1)
        // Nothing to review, so no version and no proposal.
        ->and(Proposal::count())->toBe(0)
        ->and($store->refresh()->is_live)->toBeTrue();
});

it('opens a proposal and creates no attachment when an answer differs', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    $answers = agreeingAnswers($session);
    $inputs = collect($session->questions)->firstWhere('attribute', 'inputs');
    $answers[$inputs['id']] = '4';

    $variant = $product->variants()->first();

    $outcome = service()->submit($store, $session, $answers, [$variant->id], 450_000, 'LKR');

    expect($outcome->outcome)->toBe(ConfirmationOutcome::PROPOSAL_CREATED)
        ->and($outcome->attachments)->toHaveCount(0)
        ->and($outcome->proposal->changes)->toHaveKey('inputs')
        ->and($outcome->proposal->changes['inputs']['from'])->toBe('2')
        ->and($outcome->proposal->changes['inputs']['to'])->toBe('4');

    /*
     * The single most important assertion in this milestone. The absence of an
     * attachment row *is* the block on the proposing seller, so a row appearing here
     * would let them sell a product whose description is still being argued about.
     */
    expect(Attachment::count())->toBe(0)
        ->and($store->refresh()->is_live)->toBeFalse();
});

it('treats spacing and case as agreement rather than a change', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    $answers = agreeingAnswers($session);
    $rate = collect($session->questions)->firstWhere('attribute', 'sample_rate');
    // "192 kHz" typed as "  192   KHZ ". The seller has not disagreed about anything.
    $answers[$rate['id']] = '  192   KHZ ';

    $variant = $product->variants()->first();

    $outcome = service()->submit($store, $session, $answers, [$variant->id], 450_000, 'LKR');

    expect($outcome->outcome)->toBe(ConfirmationOutcome::ATTACHED);
});

/*
|--------------------------------------------------------------------------
| A pending proposal blocks the seller
|--------------------------------------------------------------------------
*/

it('refuses a second attempt with proposal_pending while one is under review', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();

    Proposal::factory()->for($product)->for($store)->create();

    expect(fn () => service()->start($store, $product))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode())->toBe('proposal_pending'));
});

it('keeps blocking while a proposal is escalated', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();

    /*
     * Escalated means the window closed without enough votes and an administrator is
     * deciding. The seller is still waiting, so treating it as finished would let them
     * attach while their own case is still open.
     */
    Proposal::factory()->for($product)->for($store)->status(Proposal::STATUS_ESCALATED)->create();

    expect(fn () => service()->start($store, $product))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode())->toBe('proposal_pending'));
});

it('refuses with already_attached when the seller carries the product', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();

    Attachment::factory()->for($store)->for($product->variants()->first(), 'variant')->create([
        'product_id' => $product->id,
    ]);

    expect(fn () => service()->start($store, $product))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode())->toBe('already_attached'));
});

/*
|--------------------------------------------------------------------------
| The review window
|--------------------------------------------------------------------------
*/

it('closes the review window exactly three days after it opens', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    $answers = agreeingAnswers($session);
    $answers[collect($session->questions)->firstWhere('attribute', 'inputs')['id']] = '4';

    $outcome = service()->submit($store, $session, $answers, [$product->variants()->first()->id], 450_000, 'LKR');

    $proposal = $outcome->proposal;

    // Fixed platform wide, not configurable per product or per category.
    expect($proposal->review_opens_at->addDays(3)->equalTo($proposal->review_closes_at))->toBeTrue()
        ->and($proposal->review_opens_at->diffInDays($proposal->review_closes_at))->toBe(3.0);
});

/*
|--------------------------------------------------------------------------
| The voter set is frozen when the proposal opens
|--------------------------------------------------------------------------
*/

it('records the attached stores at opening time and ignores later arrivals', function (): void {
    Notification::fake();

    $product = confirmableProduct();
    $variant = $product->variants()->first();

    // Two stores already carry it when the proposal opens.
    $incumbentA = sellerStore();
    $incumbentB = sellerStore();

    foreach ([$incumbentA, $incumbentB] as $store) {
        Attachment::factory()->for($store)->for($variant, 'variant')->create(['product_id' => $product->id]);
    }

    $proposer = sellerStore();
    $session = service()->start($proposer, $product);

    $answers = agreeingAnswers($session);
    $answers[collect($session->questions)->firstWhere('attribute', 'inputs')['id']] = '4';

    $outcome = service()->submit($proposer, $session, $answers, [$variant->id], 450_000, 'LKR');
    $proposal = $outcome->proposal;

    expect($proposal->reviewers()->pluck('store_id')->sort()->values()->all())
        ->toBe(collect([$incumbentA->id, $incumbentB->id])->sort()->values()->all());

    // A third store attaches during the window. It was not attached when the proposal
    // opened, so it is not a reviewer and never becomes one.
    $latecomer = sellerStore();
    Attachment::factory()->for($latecomer)->for($variant, 'variant')->create(['product_id' => $product->id]);

    expect($proposal->reviewers()->where('store_id', $latecomer->id)->exists())->toBeFalse()
        ->and($proposal->reviewers()->count())->toBe(2);

    // The proposing store never reviews its own proposal.
    expect($proposal->reviewers()->where('store_id', $proposer->id)->exists())->toBeFalse();
});

it('makes a sole attached seller the only reviewer', function (): void {
    Notification::fake();

    $product = confirmableProduct();
    $variant = $product->variants()->first();

    $incumbent = sellerStore();
    Attachment::factory()->for($incumbent)->for($variant, 'variant')->create(['product_id' => $product->id]);

    $proposer = sellerStore();
    $session = service()->start($proposer, $product);

    $answers = agreeingAnswers($session);
    $answers[collect($session->questions)->firstWhere('attribute', 'inputs')['id']] = '4';

    $proposal = service()->submit($proposer, $session, $answers, [$variant->id], 450_000, 'LKR')->proposal;

    // Their single vote will be a majority of the votes actually cast, at M7.
    expect($proposal->reviewers()->pluck('store_id')->all())->toBe([$incumbent->id]);

    Notification::assertSentTo($incumbent->user, ProposalNeedsReview::class);
});

it('opens a proposal with no reviewers when nobody else carries the product', function (): void {
    Notification::fake();

    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    $answers = agreeingAnswers($session);
    $answers[collect($session->questions)->firstWhere('attribute', 'inputs')['id']] = '4';

    $proposal = service()->submit($store, $session, $answers, [$product->variants()->first()->id], 450_000, 'LKR')->proposal;

    /*
     * A real state rather than a bug. Nobody can review it, so it reaches its closing
     * time with no votes and escalates to an administrator, which is the defined
     * outcome for an unreviewed proposal.
     */
    expect(ProposalReviewer::count())->toBe(0);

    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The confidence score is written and never shown
|--------------------------------------------------------------------------
*/

it('writes a confidence score and band to the proposal', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    $answers = agreeingAnswers($session);
    $answers[collect($session->questions)->firstWhere('attribute', 'inputs')['id']] = 'four separate inputs';

    $proposal = service()->submit($store, $session, $answers, [$product->variants()->first()->id], 450_000, 'LKR')->proposal;

    $stored = DB::table('proposals')->where('id', $proposal->id)->first();

    // Scored by the AI from the answers, never self reported by the seller.
    expect((float) $stored->confidence_score)->toBeGreaterThan(0.0)
        ->and($stored->confidence_band)->toBeIn([Proposal::BAND_HIGH, Proposal::BAND_LOW]);
});

it('keeps the confidence score out of the model array representation', function (): void {
    $proposal = Proposal::factory()->create();

    // Not the real guarantee, which is that no resource selects it. This is the second
    // line: a careless toArray() or a debug dump must not become the leak.
    expect($proposal->toArray())->not->toHaveKey('confidence_score')
        ->and($proposal->toArray())->not->toHaveKey('confidence_band');
});

it('consumes the session on both outcomes', function (): void {
    $product = confirmableProduct();
    $store = sellerStore();
    $session = service()->start($store, $product);

    service()->submit($store, $session, agreeingAnswers($session), [$product->variants()->first()->id], 450_000, 'LKR');

    // A session that could be submitted twice would create a duplicate attachment or a
    // second proposal for the same seller and product.
    expect(AttachSession::count())->toBe(0);
});
