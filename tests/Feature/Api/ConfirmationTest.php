<?php

declare(strict_types=1);

use App\Contracts\AiProvider;
use App\Jobs\CompleteConfirmation;
use App\Models\AiJob;
use App\Models\Attachment;
use App\Models\AttachSession;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Proposal;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\ProposalNeedsReview;
use App\Services\Ai\FakeAiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * M6 The confirmation and proposal path. EP-19, EP-21, EP-22.
 *
 * The heart of the platform, over HTTP. The service level rules live in
 * ConfirmationServiceTest.php; this file asserts the wire: the two outcomes of section
 * 11.4, the registered error codes, and that no response anywhere carries a confidence
 * score.
 */
function m6Product(array $overrides = []): Product
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

    return $product->refresh();
}

function m6Seller(): User
{
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    return $user;
}

/** Opens a session through EP-21 and returns the decoded data. */
function m6StartConfirmation(User $user, Product $product): array
{
    return test()->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertOk()
        ->json('data');
}

/** Answers that agree with the record on every question, read from the stored session. */
function m6AgreeingAnswers(string $sessionId): array
{
    $answers = [];

    foreach (AttachSession::findOrFail($sessionId)->questions as $question) {
        $answers[$question['id']] = (string) ($question['current_value'] ?? 'unchanged');
    }

    return $answers;
}

function m6QuestionIdFor(string $sessionId, string $attribute): string
{
    return collect(AttachSession::findOrFail($sessionId)->questions)
        ->firstWhere('attribute', $attribute)['id'];
}

function withFailingAiProvider(): void
{
    app()->instance(AiProvider::class, new FakeAiProvider(shouldFail: true));
}

/*
|--------------------------------------------------------------------------
| EP-21 Start confirmation
|--------------------------------------------------------------------------
*/

it('returns a session and questions covering every attribute', function (): void {
    $product = m6Product();

    $data = m6StartConfirmation(m6Seller(), $product);

    expect($data['product_id'])->toBe($product->id)
        ->and($data['questions'])->not->toBeEmpty();

    $attributes = collect($data['questions'])->pluck('attribute')->all();

    expect($attributes)->toContain('name', 'category', 'inputs', 'sample_rate', 'Colour');
});

it('never sends the expected answer to the client', function (): void {
    $product = m6Product();

    $response = $this->actingAs(m6Seller(), 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertOk();

    /*
     * The record's own values are stored on the session so the comparison can use them,
     * but showing them would turn confirmation into a yes or no exercise. The point is
     * that the seller says what their unit is without being led to ours.
     */
    expect($response->json())->not->toContain('current_value')
        ->and(json_encode($response->json()))->not->toContain('current_value');

    // And the session did keep them, so the comparison at submit still works.
    expect(AttachSession::sole()->questions[0])->toHaveKey('current_value');
});

it('refuses confirmation for a user with no store', function (): void {
    $product = m6Product();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

it('refuses confirmation for a product that does not exist', function (): void {
    $this->actingAs(m6Seller(), 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => 999999])
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('refuses starting confirmation while a proposal is pending', function (): void {
    $product = m6Product();
    $user = m6Seller();

    Proposal::factory()->for($product)->for($user->store)->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'proposal_pending');
});

it('refuses starting confirmation for a product the seller already carries', function (): void {
    $product = m6Product();
    $user = m6Seller();

    Attachment::factory()->for($user->store)->for($product->variants()->first(), 'variant')
        ->create(['product_id' => $product->id]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_attached');
});

it('queues and answers 503 when the provider cannot write the questions', function (): void {
    withFailingAiProvider();

    $product = m6Product();

    $response = $this->actingAs(m6Seller(), 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable');

    expect(AiJob::find($response->json('queued_job_id'))->type)
        ->toBe(AiJob::TYPE_CONFIRMATION_QUESTIONS);
});

/*
|--------------------------------------------------------------------------
| EP-22 Submit, and the two outcomes of section 11.4
|--------------------------------------------------------------------------
*/

it('answers with outcome attached and the attachment ids when nothing differs', function (): void {
    $product = m6Product();
    $user = m6Seller();
    $session = m6StartConfirmation($user, $product);
    $variant = $product->variants()->first();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session['session_id'],
            'answers' => m6AgreeingAnswers($session['session_id']),
            'variant_ids' => [$variant->id],
            'price_minor' => 450_000,
            'currency' => 'LKR',
        ])
        ->assertCreated()
        // Distinguished by the field, never by the status code. Both outcomes are 201.
        ->assertJsonPath('data.outcome', 'attached');

    expect($response->json('data.attachment_ids'))->toHaveCount(1)
        ->and(Proposal::count())->toBe(0)
        ->and($user->store->refresh()->is_live)->toBeTrue();
});

it('answers with outcome proposal_created and no attachment when something differs', function (): void {
    Notification::fake();

    $product = m6Product();
    $user = m6Seller();
    $session = m6StartConfirmation($user, $product);

    $answers = m6AgreeingAnswers($session['session_id']);
    $answers[m6QuestionIdFor($session['session_id'], 'inputs')] = '4';

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session['session_id'],
            'answers' => $answers,
            'variant_ids' => [$product->variants()->first()->id],
            'price_minor' => 450_000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.outcome', 'proposal_created');

    $proposal = Proposal::sole();

    expect($response->json('data.proposal_id'))->toBe($proposal->id)
        ->and($response->json('data.review_closes_at'))->toBeString()
        // No attachment_ids key on this branch, so a client that forgets to branch on
        // outcome fails loudly rather than rendering an attached state.
        ->and($response->json('data'))->not->toHaveKey('attachment_ids');

    /*
     * The whole mechanism. The absence of an attachment row is the block on the
     * proposing seller, and the store stays invisible to buyers until it resolves.
     */
    expect(Attachment::count())->toBe(0)
        ->and($user->store->refresh()->is_live)->toBeFalse();
});

it('refuses an unanswered question with confirmation_incomplete', function (): void {
    $product = m6Product();
    $user = m6Seller();
    $session = m6StartConfirmation($user, $product);

    $answers = m6AgreeingAnswers($session['session_id']);
    $answers[m6QuestionIdFor($session['session_id'], 'inputs')] = '   ';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session['session_id'],
            'answers' => $answers,
            'variant_ids' => [$product->variants()->first()->id],
            'price_minor' => 450_000,
        ])
        ->assertStatus(422)
        // Its own registered code, not validation_failed. The flow cannot be skipped or
        // partially completed, and the client shows a different screen for each.
        ->assertJsonPath('code', 'confirmation_incomplete');

    expect(Attachment::count())->toBe(0)
        ->and(Proposal::count())->toBe(0);
});

it('refuses a second submission with proposal_pending while one is under review', function (): void {
    Notification::fake();

    $product = m6Product();
    $user = m6Seller();

    // First submission opens a proposal.
    $first = m6StartConfirmation($user, $product);
    $answers = m6AgreeingAnswers($first['session_id']);
    $answers[m6QuestionIdFor($first['session_id'], 'inputs')] = '4';

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $first['session_id'],
            'answers' => $answers,
            'variant_ids' => [$product->variants()->first()->id],
            'price_minor' => 450_000,
        ])
        ->assertCreated();

    // A second attempt on the same product is refused before it can start.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'proposal_pending');

    expect(Proposal::count())->toBe(1);
});

it('refuses a variant that belongs to another product', function (): void {
    $product = m6Product();
    $other = m6Product(['name' => 'Something Else', 'slug' => 'something-else']);
    $user = m6Seller();
    $session = m6StartConfirmation($user, $product);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session['session_id'],
            'answers' => m6AgreeingAnswers($session['session_id']),
            'variant_ids' => [$other->variants()->first()->id],
            'price_minor' => 450_000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect(Attachment::count())->toBe(0);
});

it('rejects a price of zero and a decimal price', function (): void {
    $product = m6Product();
    $user = m6Seller();
    $session = m6StartConfirmation($user, $product);

    foreach ([0, 4599.9] as $price) {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/attach/confirm/submit', [
                'session_id' => $session['session_id'],
                'answers' => m6AgreeingAnswers($session['session_id']),
                'variant_ids' => [$product->variants()->first()->id],
                'price_minor' => $price,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }
});

it('refuses a confirmation session belonging to another store', function (): void {
    $product = m6Product();
    $session = m6StartConfirmation(m6Seller(), $product);

    $this->actingAs(m6Seller(), 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session['session_id'],
            'answers' => m6AgreeingAnswers($session['session_id']),
            'variant_ids' => [$product->variants()->first()->id],
            'price_minor' => 450_000,
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('emails every frozen reviewer when a proposal opens', function (): void {
    Notification::fake();

    $product = m6Product();
    $variant = $product->variants()->first();

    $incumbent = m6Seller();
    Attachment::factory()->for($incumbent->store)->for($variant, 'variant')
        ->create(['product_id' => $product->id]);

    $proposer = m6Seller();
    $session = m6StartConfirmation($proposer, $product);
    $answers = m6AgreeingAnswers($session['session_id']);
    $answers[m6QuestionIdFor($session['session_id'], 'inputs')] = '4';

    $this->actingAs($proposer, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session['session_id'],
            'answers' => $answers,
            'variant_ids' => [$variant->id],
            'price_minor' => 450_000,
        ])
        ->assertCreated();

    // Email is the only notification surface this platform has. A reviewer who is not
    // emailed never learns they are expected to vote.
    Notification::assertSentTo($incumbent, ProposalNeedsReview::class);
    Notification::assertNotSentTo($proposer, ProposalNeedsReview::class);
});

it('queues the whole submission and answers 503 when the provider cannot score it', function (): void {
    Queue::fake();

    $product = m6Product();
    $user = m6Seller();
    $session = m6StartConfirmation($user, $product);

    // Questions are already written, so only the scoring call fails.
    withFailingAiProvider();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $session['session_id'],
            'answers' => m6AgreeingAnswers($session['session_id']),
            'variant_ids' => [$product->variants()->first()->id],
            'price_minor' => 450_000,
        ])
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable');

    $jobId = $response->json('queued_job_id');

    expect(AiJob::find($jobId)->type)->toBe(AiJob::TYPE_CONFIRMATION_OUTCOME)
        // The answers travel with the job, so the work is genuinely saved rather than
        // only promised.
        ->and(AiJob::find($jobId)->payload)->toHaveKey('answers')
        ->and(AttachSession::find($session['session_id'])->ai_job_id)->toBe($jobId);

    Queue::assertPushed(CompleteConfirmation::class);
});

it('returns the same job id rather than queueing a second submission', function (): void {
    Queue::fake();

    $product = m6Product();
    $user = m6Seller();
    $session = m6StartConfirmation($user, $product);

    withFailingAiProvider();

    $payload = [
        'session_id' => $session['session_id'],
        'answers' => m6AgreeingAnswers($session['session_id']),
        'variant_ids' => [$product->variants()->first()->id],
        'price_minor' => 450_000,
    ];

    $first = $this->actingAs($user, 'sanctum')->postJson('/api/attach/confirm/submit', $payload)
        ->assertStatus(503)->json('queued_job_id');

    $second = $this->actingAs($user, 'sanctum')->postJson('/api/attach/confirm/submit', $payload)
        ->assertStatus(503)->json('queued_job_id');

    /*
     * Two jobs completing the same session would race to create a duplicate proposal.
     * Returning the same id also directs the seller to the submission already in
     * flight, which is what the interface needs.
     */
    expect($second)->toBe($first)
        ->and(AiJob::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The confidence score reaches no response
|--------------------------------------------------------------------------
*/

it('never returns a confidence score from any confirmation endpoint', function (): void {
    Notification::fake();

    $product = m6Product();
    $user = m6Seller();

    $start = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/start', ['product_id' => $product->id])->assertOk();

    $sessionId = $start->json('data.session_id');
    $answers = m6AgreeingAnswers($sessionId);
    $answers[m6QuestionIdFor($sessionId, 'inputs')] = '4';

    $submit = $this->actingAs($user, 'sanctum')
        ->postJson('/api/attach/confirm/submit', [
            'session_id' => $sessionId,
            'answers' => $answers,
            'variant_ids' => [$product->variants()->first()->id],
            'price_minor' => 450_000,
        ])->assertCreated();

    $listings = $this->actingAs($user, 'sanctum')->getJson('/api/stores/mine/listings')->assertOk();

    foreach ([$start, $submit, $listings] as $response) {
        $body = json_encode($response->json());

        expect($body)->not->toContain('confidence_score')
            ->and($body)->not->toContain('confidence_band')
            ->and($body)->not->toContain('created_by_store_id');
    }

    // It was written, though. Stored and never shown is the whole design.
    expect((float) DB::table('proposals')->value('confidence_score'))
        ->toBeGreaterThan(0.0);
});

/*
|--------------------------------------------------------------------------
| EP-19 Listings, and the blocked state only a proposal can produce
|--------------------------------------------------------------------------
*/

it('returns an empty listings payload for a store carrying nothing', function (): void {
    $this->actingAs(m6Seller(), 'sanctum')
        ->getJson('/api/stores/mine/listings')
        ->assertOk()
        ->assertJsonPath('data.listings', [])
        ->assertJsonPath('data.blocked', []);
});

it('groups a store\'s attachments by product', function (): void {
    $product = m6Product();
    $user = m6Seller();

    $second = Variant::factory()->for($product)->combination(['Colour' => 'Grey'])->create();

    foreach ([$product->variants()->first(), $second] as $variant) {
        Attachment::factory()->for($user->store)->for($variant, 'variant')
            ->create(['product_id' => $product->id, 'price_minor' => 450_000]);
    }

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/stores/mine/listings')
        ->assertOk()
        // One product, two versions under it. A seller thinks in products, not in rows
        // that happen to share a name.
        ->assertJsonCount(1, 'data.listings')
        ->assertJsonCount(2, 'data.listings.0.variants')
        ->assertJsonPath('data.listings.0.product.slug', $product->slug)
        ->assertJsonPath('data.listings.0.variants.0.price_minor', 450_000);
});

it('reports a pending proposal as blocked, with no listing for it', function (): void {
    $product = m6Product();
    $user = m6Seller();

    Proposal::factory()->for($product)->for($user->store)->create([
        'changes' => ['inputs' => ['from' => '2', 'to' => '4']],
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/stores/mine/listings')
        ->assertOk()
        /*
         * The reason this endpoint returns two lists. There is no attachment row, so a
         * screen built from listings alone would show nothing at all and leave the
         * seller wondering where their submission went.
         */
        ->assertJsonPath('data.listings', [])
        ->assertJsonCount(1, 'data.blocked')
        ->assertJsonPath('data.blocked.0.status', 'pending')
        ->assertJsonPath('data.blocked.0.product.slug', $product->slug)
        ->assertJsonPath('data.blocked.0.changed_fields', ['inputs']);
});

it('reports an escalated proposal as blocked too', function (): void {
    $product = m6Product();
    $user = m6Seller();

    // Waiting on an administrator rather than on peers, but still waiting.
    Proposal::factory()->for($product)->for($user->store)
        ->status(Proposal::STATUS_ESCALATED)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/stores/mine/listings')
        ->assertOk()
        ->assertJsonCount(1, 'data.blocked')
        ->assertJsonPath('data.blocked.0.status', 'escalated');
});

it('leaves a resolved proposal out of the blocked list', function (): void {
    $product = m6Product();
    $user = m6Seller();

    Proposal::factory()->for($product)->for($user->store)
        ->status(Proposal::STATUS_APPROVED)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/stores/mine/listings')
        ->assertOk()
        ->assertJsonPath('data.blocked', []);
});

it('refuses listings for a user with no store', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/stores/mine/listings')
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

it('shows one seller only their own listings', function (): void {
    $product = m6Product();
    $mine = m6Seller();
    $theirs = m6Seller();

    Attachment::factory()->for($theirs->store)->for($product->variants()->first(), 'variant')
        ->create(['product_id' => $product->id]);

    $this->actingAs($mine, 'sanctum')
        ->getJson('/api/stores/mine/listings')
        ->assertOk()
        ->assertJsonPath('data.listings', []);
});
