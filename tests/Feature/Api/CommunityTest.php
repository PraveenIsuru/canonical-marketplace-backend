<?php

declare(strict_types=1);

use App\Contracts\AiProvider;
use App\Jobs\CompleteVerification;
use App\Jobs\SummariseCommunity;
use App\Models\AiJob;
use App\Models\CommunityPost;
use App\Models\CommunitySummary;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\VerificationAttempt;
use App\Services\Ai\FakeAiProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * M9 Community and verification.
 *
 * The build plan's list, item by item: posting refused without verification for that
 * specific product, verification on one product granting nothing on another, the
 * attempt ceiling of five enforced per user per product, **the photograph deleted on
 * both pass and fail** with the timestamp set, no response containing a photograph
 * path, and soft deleted posts hidden along with their replies.
 *
 * The fake provider decides verification from the photograph's own bytes: a file
 * containing the issued code passes, anything else fails. That is what makes both
 * outcomes reachable on demand, which matters because the photograph deletion has to be
 * proven on the failing path as much as the passing one.
 */
function m9_product(string $name = 'Aurora Field Recorder FR-2'): Product
{
    return Product::factory()->create(['name' => $name, 'category' => 'Audio']);
}

function m9_disk(): string
{
    return (string) config('filesystems.verification_photos', 'verification_photos');
}

/** A photograph whose contents carry the code, so the fake provider passes it. */
function m9_photo(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('proof.jpg', $contents);
}

/** Walks a user all the way to verified on one product. */
function m9_verify(User $user, Product $product): void
{
    test()->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->assertOk();

    $code = VerificationAttempt::where('user_id', $user->id)
        ->where('product_id', $product->id)
        ->latest('id')
        ->value('generated_code');

    test()->actingAs($user, 'sanctum')
        ->post("/api/products/{$product->slug}/verification/submit", [
            'photo' => m9_photo("handwritten {$code} beside the unit"),
        ])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'passed');
}

beforeEach(function (): void {
    Storage::fake(m9_disk());

    /*
     * The `verification` limiter is 5 per minute, and several of these tests walk a
     * buyer through all five attempts, which is ten requests. The ceiling under test is
     * the five attempts per product enforced in the service; the per minute limiter is
     * a separate rule, and leaving it on here would fail these tests for a reason
     * unrelated to what they assert.
     */
    test()->withoutMiddleware(ThrottleRequests::class);
});

/*
|--------------------------------------------------------------------------
| EP-34, EP-35 Verifying
|--------------------------------------------------------------------------
*/

it('issues a code without spending an attempt', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->assertOk()
        ->assertJsonPath('data.attempts_remaining', 5)
        ->assertJsonStructure(['data' => ['code', 'attempts_remaining']]);

    // Started, not spent. A buyer who cannot photograph the product today loses nothing.
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/verification")
        ->assertOk()
        ->assertJsonPath('data.attempts_used', 0)
        ->assertJsonPath('data.latest_outcome', 'pending');
});

it('returns the same code when a buyer starts twice', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    $first = $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->json('data.code');

    // Otherwise a refresh would invalidate the code already written on paper.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->assertOk()
        ->assertJsonPath('data.code', $first);

    expect(VerificationAttempt::count())->toBe(1);
});

it('passes a photograph carrying the code and deletes it', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    $code = $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->json('data.code');

    $this->actingAs($user, 'sanctum')
        ->post("/api/products/{$product->slug}/verification/submit", [
            'photo' => m9_photo("wrote {$code} on paper"),
        ])
        ->assertOk()
        ->assertJsonPath('data.outcome', 'passed')
        ->assertJsonPath('data.attempts_remaining', 4);

    $attempt = VerificationAttempt::firstOrFail();

    expect($attempt->outcome)->toBe('passed')
        ->and($attempt->photo_deleted_at)->not->toBeNull()
        // Invariant 7, on the passing path.
        ->and(Storage::disk(m9_disk())->allFiles())->toBe([]);
});

it('fails a photograph without the code and deletes it just the same', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->post("/api/products/{$product->slug}/verification/submit", [
            'photo' => m9_photo('a photograph of nothing in particular'),
        ])
        // A failure is an ordinary outcome, not a bad request.
        ->assertOk()
        ->assertJsonPath('data.outcome', 'failed')
        ->assertJsonPath('data.attempts_remaining', 4);

    $attempt = VerificationAttempt::firstOrFail();

    expect($attempt->outcome)->toBe('failed')
        // The photograph goes on a failure too. This is the half that is easy to forget.
        ->and($attempt->photo_deleted_at)->not->toBeNull()
        ->and(Storage::disk(m9_disk())->allFiles())->toBe([])
        // The reason survives it, so the buyer can be told why.
        ->and($attempt->ai_reasoning)->not->toBeNull();
});

it('enforces a ceiling of five attempts per user per product', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->slug}/verification/start")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->slug}/verification/submit", [
                'photo' => m9_photo('nothing useful'),
            ])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'failed');
    }

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/verification")
        ->assertOk()
        ->assertJsonPath('data.attempts_used', 5)
        ->assertJsonPath('data.attempts_remaining', 0)
        ->assertJsonPath('data.can_attempt', false);

    // No appeal, no administrator reset, no way to buy more. Deliberately final.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->assertStatus(403)
        ->assertJsonPath('code', 'attempts_exhausted');
});

it('counts attempts per product, so one product exhausted leaves another untouched', function (): void {
    $user = User::factory()->create();
    $exhausted = m9_product('Exhausted Product');
    $fresh = m9_product('Fresh Product');

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$exhausted->slug}/verification/start")->assertOk();
        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$exhausted->slug}/verification/submit", ['photo' => m9_photo('no')])
            ->assertOk();
    }

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/products/{$fresh->slug}/verification")
        ->assertOk()
        ->assertJsonPath('data.attempts_remaining', 5)
        ->assertJsonPath('data.can_attempt', true);
});

it('counts attempts per user, so one buyer exhausting theirs leaves another free', function (): void {
    $product = m9_product();
    $spent = User::factory()->create();
    $other = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($spent, 'sanctum')
            ->postJson("/api/products/{$product->slug}/verification/start")->assertOk();
        $this->actingAs($spent, 'sanctum')
            ->post("/api/products/{$product->slug}/verification/submit", ['photo' => m9_photo('no')])
            ->assertOk();
    }

    $this->actingAs($other, 'sanctum')
        ->getJson("/api/products/{$product->slug}/verification")
        ->assertOk()
        ->assertJsonPath('data.attempts_remaining', 5);
});

it('refuses to start again once verified', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    m9_verify($user, $product);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")
        ->assertStatus(403);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/verification")
        ->assertOk()
        ->assertJsonPath('data.is_verified', true)
        ->assertJsonPath('data.can_attempt', false);
});

it('refuses an oversized photograph with its own registered code', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")->assertOk();

    // Its own code, not validation_failed, because the client branches on the code.
    $this->actingAs($user, 'sanctum')
        ->post("/api/products/{$product->slug}/verification/submit", [
            'photo' => UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'file_too_large');
});

it('queues the judgement and keeps the photograph when the provider is down', function (): void {
    /*
     * Faked so the sync queue does not run the job inline. Run inline it throws the same
     * provider failure straight back through the request, and the 503 the contract
     * defines is never reached.
     */
    Queue::fake();
    app()->bind(AiProvider::class, fn (): FakeAiProvider => new FakeAiProvider(shouldFail: true));

    $user = User::factory()->create();
    $product = m9_product();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")->assertOk();

    $response = $this->actingAs($user, 'sanctum')
        ->post("/api/products/{$product->slug}/verification/submit", ['photo' => m9_photo('anything')])
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable');

    // Section 8: the job id is at the top level, not inside data.
    expect($response->json('queued_job_id'))->not->toBeNull()
        // The photograph survives only until the queued job concludes.
        ->and(Storage::disk(m9_disk())->allFiles())->not->toBe([])
        // The attempt is not spent on the platform's outage.
        ->and(VerificationAttempt::firstOrFail()->outcome)->toBe('pending');

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/products/{$product->slug}/verification")
        ->assertOk()
        ->assertJsonPath('data.pending_job_id', $response->json('queued_job_id'))
        ->assertJsonPath('data.attempts_used', 0);
});

it('deletes the photograph when a queued judgement gives up for good', function (): void {
    Queue::fake();
    app()->bind(AiProvider::class, fn (): FakeAiProvider => new FakeAiProvider(shouldFail: true));

    $user = User::factory()->create();
    $product = m9_product();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/verification/start")->assertOk();

    $jobId = $this->actingAs($user, 'sanctum')
        ->post("/api/products/{$product->slug}/verification/submit", ['photo' => m9_photo('anything')])
        ->json('queued_job_id');

    $path = Storage::disk(m9_disk())->allFiles()[0];

    /*
     * The provider never recovered. The photograph goes anyway: it was collected for one
     * purpose, that purpose can no longer be served, and keeping it because the
     * judgement failed would be the one way a photograph outlives its verification.
     */
    (new CompleteVerification($jobId, VerificationAttempt::firstOrFail()->id, $path))
        ->failed(new RuntimeException('provider down'));

    expect(Storage::disk(m9_disk())->allFiles())->toBe([])
        // And the attempt is still unspent, because the outage was not the buyer's fault.
        ->and(VerificationAttempt::firstOrFail()->outcome)->toBe('pending')
        ->and(AiJob::find($jobId)?->status)->toBe('failed');
});

it('sweeps a photograph left behind by interrupted work', function (): void {
    Storage::disk(m9_disk())->put('attempts/orphan.jpg', 'left behind');

    // Younger than the threshold, so still possibly in flight.
    $this->artisan('verification:cleanup', ['--hours' => 6])->assertExitCode(0);
    expect(Storage::disk(m9_disk())->exists('attempts/orphan.jpg'))->toBeTrue();

    /*
     * Old enough that nothing is coming back for it. Time is moved rather than the
     * threshold dropped to zero: the command clamps to a minimum of one hour on purpose,
     * so a photograph still being judged cannot be pulled out from under a running job.
     */
    $this->travel(7)->hours();

    $this->artisan('verification:cleanup', ['--hours' => 6])->assertExitCode(0);
    expect(Storage::disk(m9_disk())->exists('attempts/orphan.jpg'))->toBeFalse();
});

it('never returns a photograph path from any verification endpoint', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    $bodies = [
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->slug}/verification/start")->getContent(),
        $this->actingAs($user, 'sanctum')
            ->post("/api/products/{$product->slug}/verification/submit", ['photo' => m9_photo('no')])
            ->getContent(),
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/products/{$product->slug}/verification")->getContent(),
    ];

    /*
     * Read as text rather than by key, so a nested or renamed occurrence is caught too.
     * Section 6 lists photograph paths alongside the confidence score.
     */
    foreach ($bodies as $body) {
        expect($body)->not->toContain('attempts/')
            ->and($body)->not->toContain('verification-photos')
            ->and($body)->not->toContain('photo_path')
            ->and($body)->not->toContain('.jpg');
    }
});

/*
|--------------------------------------------------------------------------
| EP-31, EP-32, EP-57 The discussion
|--------------------------------------------------------------------------
*/

it('refuses a post from someone who has not verified that product', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", ['body' => 'Mine rattles.'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_verified');

    expect(CommunityPost::count())->toBe(0);
});

it('grants nothing on another product when one is verified', function (): void {
    $user = User::factory()->create();
    $verified = m9_product('Verified Product');
    $other = m9_product('Other Product');

    m9_verify($user, $verified);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$verified->slug}/community/posts", ['body' => 'Works well.'])
        ->assertStatus(201);

    // The whole point of scoping verification per product.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$other->slug}/community/posts", ['body' => 'Also works well.'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'not_verified');
});

it('lets a verified owner post and read it back publicly', function (): void {
    $user = User::factory()->create(['name' => 'Nadia']);
    $product = m9_product();

    m9_verify($user, $product);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", [
            'body' => 'The battery lasts about two days.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.author.name', 'Nadia')
        ->assertJsonPath('data.reply_count', 0);

    // Public, with no token at all.
    $this->getJson("/api/products/{$product->slug}/community/posts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'The battery lasts about two days.');
});

it('never names the author store on a post', function (): void {
    $user = User::factory()->create(['name' => 'Nadia']);
    Store::factory()->for($user)->create(['name' => 'Fort Electronics']);
    $product = m9_product();

    m9_verify($user, $product);

    $body = $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", ['body' => 'Good unit.'])
        ->getContent();

    // A user who runs a store posts as a verified buyer. Naming the store would turn a
    // discussion into advertising.
    expect($body)->not->toContain('Fort Electronics')
        ->and($body)->not->toContain('store');
});

it('threads replies one level deep and refuses to nest further', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    m9_verify($user, $product);

    $parent = $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", ['body' => 'Anyone else?'])
        ->json('data.id');

    $reply = $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", [
            'body' => 'Yes, mine too.',
            'parent_id' => $parent,
        ])
        ->assertStatus(201)
        ->json('data.id');

    // A tree on a product discussion is harder to read than a flat list.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", [
            'body' => 'Replying to a reply.',
            'parent_id' => $reply,
        ])
        ->assertNotFound();

    $this->getJson("/api/products/{$product->slug}/community/posts")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reply_count', 1);

    $this->getJson("/api/products/{$product->slug}/community/posts/{$parent}/replies")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reply);
});

it('hides a soft deleted post along with its replies', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    m9_verify($user, $product);

    $parent = $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", ['body' => 'Parent post.'])
        ->json('data.id');

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", [
            'body' => 'A reply that must go with it.',
            'parent_id' => $parent,
        ])
        ->assertStatus(201);

    CommunityPost::findOrFail($parent)->delete();

    // Gone from the list, and no tombstone.
    $this->getJson("/api/products/{$product->slug}/community/posts")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    /*
     * And its replies go with it. Eloquent hides the parent on its own but would happily
     * serve the children, leaving half a conversation with its subject missing.
     */
    $this->getJson("/api/products/{$product->slug}/community/posts/{$parent}/replies")
        ->assertNotFound();
});

it('paginates the discussion by cursor rather than page number', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    m9_verify($user, $product);

    foreach (range(1, 3) as $n) {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->slug}/community/posts", ['body' => "Post {$n}"])
            ->assertStatus(201);
    }

    // Section 2: community posts use cursor pagination, not the length aware paginator.
    $this->getJson("/api/products/{$product->slug}/community/posts?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data', 'meta' => ['next_cursor']]);
});

it('rejects an empty post body', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    m9_verify($user, $product);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/products/{$product->slug}/community/posts", ['body' => ''])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('reads the discussion with no token at all', function (): void {
    $product = m9_product();

    // Invariant 9: public catalogue routes work with no token and resolve no session.
    $this->getJson("/api/products/{$product->slug}/community/posts")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses posting without a token', function (): void {
    $product = m9_product();

    $this->postJson("/api/products/{$product->slug}/community/posts", ['body' => 'Hello'])
        ->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| The summary
|--------------------------------------------------------------------------
*/

it('writes a summary once a discussion has enough to describe', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    m9_verify($user, $product);

    foreach (range(1, 3) as $n) {
        CommunityPost::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'body' => "An observation number {$n}.",
        ]);
    }

    (new SummariseCommunity($product->id))->handle(app(AiProvider::class));

    $summary = CommunitySummary::where('product_id', $product->id)->first();

    expect($summary)->not->toBeNull()
        ->and($summary->post_count_at_generation)->toBe(3);

    // EP-53 has existed since M2 and returned null all this time. This is what fills it.
    $this->getJson("/api/products/{$product->slug}/summary")
        ->assertOk()
        ->assertJsonPath('data.summary', $summary->summary_text);
});

it('writes no summary for a discussion too small to describe', function (): void {
    $user = User::factory()->create();
    $product = m9_product();

    CommunityPost::create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'body' => 'The only comment.',
    ]);

    // Summarising two comments produces a sentence longer than the thing it summarises.
    (new SummariseCommunity($product->id))->handle(app(AiProvider::class));

    expect(CommunitySummary::count())->toBe(0);
});

it('leaves the previous summary in place when the provider is down', function (): void {
    $product = m9_product();
    $user = User::factory()->create();

    CommunitySummary::create([
        'product_id' => $product->id,
        'summary_text' => 'Yesterday\'s summary.',
        'post_count_at_generation' => 3,
        'generated_at' => now()->subDay(),
    ]);

    foreach (range(1, 4) as $n) {
        CommunityPost::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'body' => "Comment {$n}.",
        ]);
    }

    // Nobody is waiting on this, and yesterday's summary beats none.
    (new SummariseCommunity($product->id))->handle(new FakeAiProvider(shouldFail: true));

    expect(CommunitySummary::firstOrFail()->summary_text)->toBe('Yesterday\'s summary.');
});

it('never emits a rating or a score in a summary', function (): void {
    $product = m9_product();
    $user = User::factory()->create();

    foreach (range(1, 3) as $n) {
        CommunityPost::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'body' => "Comment {$n}.",
        ]);
    }

    (new SummariseCommunity($product->id))->handle(app(AiProvider::class));

    $body = $this->getJson("/api/products/{$product->slug}/summary")->getContent();

    // The platform has no star score and no sentiment number anywhere.
    expect($body)->not->toContain('rating')
        ->and($body)->not->toContain('score')
        ->and($body)->not->toContain('stars');
});
