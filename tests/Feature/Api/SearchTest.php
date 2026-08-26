<?php

declare(strict_types=1);

use App\Contracts\AiProvider;
use App\Jobs\InterpretSearchQuery;
use App\Models\AiJob;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\FakeAiProvider;
use Illuminate\Support\Facades\Queue;

/**
 * M3 Search. EP-14 and EP-15.
 *
 * Two endpoints over the same query whose failure behaviour is deliberately opposite,
 * and that difference is what most of this file exists to pin down.
 *
 * No test here touches a network. The AI provider is the fake adapter, and the search
 * engine is a null engine, so the suite runs offline and free. What is being asserted
 * is the platform's own decision making, not Meilisearch's relevance ranking.
 */

/** Forces the AI provider into its failing mode for the current test. */
function withFailingAi(): void
{
    app()->instance(AiProvider::class, new FakeAiProvider(shouldFail: true));
}

/** A seller who can reach EP-15: a user with a store. */
function sellerUser(): User
{
    $user = User::factory()->create();
    Store::factory()->for($user)->create();

    return $user;
}

/*
|--------------------------------------------------------------------------
| EP-14 Buyer search. The availability floor.
|--------------------------------------------------------------------------
*/

it('returns ai mode when the provider answers', function (): void {
    $this->getJson('/api/search?q=black+smartphone')
        ->assertOk()
        // Read from the body, not inferred. The backend is the single authority on
        // which path served a query.
        ->assertJsonPath('mode', 'ai')
        ->assertJsonStructure(['mode', 'data', 'links', 'meta']);
});

it('falls back to keyword results when the provider fails, still answering 200', function (): void {
    withFailingAi();

    $this->getJson('/api/search?q=black+smartphone')
        ->assertOk()
        ->assertJsonPath('mode', 'keyword');
});

it('never returns ai_unavailable from buyer search', function (): void {
    withFailingAi();

    $response = $this->getJson('/api/search?q=anything');

    // The single endpoint in the platform where provider failure is not surfaced as a
    // failure. A buyer who cannot search cannot find anything at all.
    $response->assertOk();
    expect($response->json('code'))->toBeNull()
        ->and($response->json('queued_job_id'))->toBeNull();
});

it('queues no work when buyer search hits a provider failure', function (): void {
    Queue::fake();
    withFailingAi();

    $this->getJson('/api/search?q=anything')->assertOk();

    Queue::assertNothingPushed();
    expect(AiJob::count())->toBe(0);
});

it('puts mode beside data, never inside it', function (): void {
    $body = $this->getJson('/api/search?q=phone')->assertOk()->json();

    // Its position is part of the contract, not a formatting choice.
    expect($body)->toHaveKey('mode')
        ->and($body['data'])->not->toHaveKey('mode');
});

it('returns an empty result set in ai mode with the mode still present', function (): void {
    $this->getJson('/api/search?q=nothing-matches-this')
        ->assertOk()
        ->assertJsonPath('mode', 'ai')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');
});

it('returns an empty result set in keyword mode with the mode still present', function (): void {
    withFailingAi();

    // The client shows both the no match message and the fallback notice, so a visitor
    // can tell a weak query from a degraded service.
    $this->getJson('/api/search?q=nothing-matches-this')
        ->assertOk()
        ->assertJsonPath('mode', 'keyword')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonCount(0, 'data');
});

it('behaves identically with and without a token', function (): void {
    $anonymous = $this->getJson('/api/search?q=phone')->assertOk()->json();

    $authenticated = $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/search?q=phone')
        ->assertOk()
        ->json();

    expect($authenticated)->toBe($anonymous);
});

it('starts no session on buyer search', function (): void {
    $response = $this->getJson('/api/search?q=phone')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});

it('requires a query', function (): void {
    $this->getJson('/api/search')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['code', 'message', 'errors' => ['q']]);
});

/*
|--------------------------------------------------------------------------
| EP-15 Seller catalogue search. Not an exception to the AI rule.
|--------------------------------------------------------------------------
*/

it('returns ai mode for a seller when the provider answers', function (): void {
    $this->actingAs(sellerUser(), 'sanctum')
        ->getJson('/api/seller/catalogue-search?q=smartphone')
        ->assertOk()
        ->assertJsonPath('mode', 'ai');
});

it('returns 503 ai_unavailable with a top level queued job id when the provider fails', function (): void {
    /*
     * Queue::fake() is required, not cosmetic. phpunit.xml runs the queue on the sync
     * driver, which executes a dispatched job inline. The provider is still in failing
     * mode at that moment, so the job throws again and turns the 503 into a 500. On a
     * real driver dispatch only inserts a row, which is the behaviour being asserted.
     */
    Queue::fake();
    withFailingAi();

    $response = $this->actingAs(sellerUser(), 'sanctum')
        ->getJson('/api/seller/catalogue-search?q=smartphone')
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable');

    // Top level, outside data. That is where the client looks for it.
    $jobId = $response->json('queued_job_id');

    expect($jobId)->toBeString()
        ->and($response->json('data'))->toBeNull()
        ->and(AiJob::find($jobId))->not->toBeNull();
});

it('queues the work so a blocked seller flow can be recovered', function (): void {
    Queue::fake();
    withFailingAi();

    $this->actingAs(sellerUser(), 'sanctum')
        ->getJson('/api/seller/catalogue-search?q=smartphone')
        ->assertStatus(503);

    Queue::assertPushed(InterpretSearchQuery::class);

    $job = AiJob::sole();
    expect($job->status)->toBe(AiJob::STATUS_QUEUED)
        ->and($job->type)->toBe(AiJob::TYPE_SEARCH_INTERPRETATION)
        ->and($job->payload['query'])->toBe('smartphone');
});

it('diverges from buyer search on exactly the same failure', function (): void {
    Queue::fake();
    withFailingAi();

    // The heart of this milestone. One provider failure, two deliberately opposite
    // outcomes: the buyer gets results, the seller gets stopped.
    $buyer = $this->getJson('/api/search?q=smartphone');

    $seller = $this->actingAs(sellerUser(), 'sanctum')
        ->getJson('/api/seller/catalogue-search?q=smartphone');

    expect($buyer->status())->toBe(200)
        ->and($buyer->json('mode'))->toBe('keyword')
        ->and($seller->status())->toBe(503)
        ->and($seller->json('code'))->toBe('ai_unavailable');
});

it('never falls back to keyword results for a seller', function (): void {
    Queue::fake();
    withFailingAi();

    $response = $this->actingAs(sellerUser(), 'sanctum')
        ->getJson('/api/seller/catalogue-search?q=smartphone');

    // A degraded result here could let a seller past duplicate detection and create a
    // second canonical record for a product that already exists.
    expect($response->json('mode'))->toBeNull();
});

it('refuses a seller route for a user with no store', function (): void {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/seller/catalogue-search?q=smartphone')
        ->assertForbidden()
        ->assertJsonPath('code', 'store_required');
});

it('refuses seller catalogue search without a token', function (): void {
    $this->getJson('/api/seller/catalogue-search?q=smartphone')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

/*
|--------------------------------------------------------------------------
| The queued job itself
|--------------------------------------------------------------------------
*/

it('completes a queued interpretation once the provider recovers', function (): void {
    $job = AiJob::create([
        'user_id' => sellerUser()->id,
        'type' => AiJob::TYPE_SEARCH_INTERPRETATION,
        'status' => AiJob::STATUS_QUEUED,
        'payload' => ['query' => 'a good black smartphone', 'category' => null],
    ]);

    // The provider is healthy again by the time the queue runs it.
    (new InterpretSearchQuery($job->id))->handle(new FakeAiProvider);

    $job->refresh();

    expect($job->status)->toBe(AiJob::STATUS_COMPLETED)
        ->and($job->result['terms'])->toContain('smartphone')
        ->and($job->completed_at)->not->toBeNull();
});

it('records a failure so a polling client is not left waiting forever', function (): void {
    $job = AiJob::create([
        'type' => AiJob::TYPE_SEARCH_INTERPRETATION,
        'status' => AiJob::STATUS_QUEUED,
        'payload' => ['query' => 'anything'],
    ]);

    (new InterpretSearchQuery($job->id))->failed(new RuntimeException('provider down'));

    expect($job->refresh()->status)->toBe(AiJob::STATUS_FAILED)
        ->and($job->failure_reason)->toBe('provider down');
});

/*
|--------------------------------------------------------------------------
| The fake adapter, which every other test depends on
|--------------------------------------------------------------------------
*/

it('interprets a query into something different from the raw string', function (): void {
    $interpretation = (new FakeAiProvider)->interpretSearchQuery('I am looking for a good black smartphone');

    // If interpretation returned the query unchanged, no test could tell which path
    // served a response by looking at the results.
    expect($interpretation->terms)->not->toBe('I am looking for a good black smartphone')
        ->and($interpretation->terms)->toContain('smartphone')
        ->and($interpretation->terms)->not->toContain('looking');
});

it('falls back to the raw words when a query is nothing but filler', function (): void {
    $interpretation = (new FakeAiProvider)->interpretSearchQuery('the a is for');

    // Handing the index an empty string would return the entire catalogue as though
    // every product matched.
    expect($interpretation->terms)->not->toBe('');
});

it('throws rather than degrading when set to fail', function (): void {
    expect(fn () => (new FakeAiProvider(shouldFail: true))->interpretSearchQuery('anything'))
        ->toThrow(AiUnavailable::class);
});
