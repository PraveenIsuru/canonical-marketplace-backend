<?php

declare(strict_types=1);

use App\Jobs\RevalidateProductPage;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalogue\ProductVersionService;
use App\Services\Proposals\ProposalResolutionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * M12 EP-51, the revalidation webhook.
 *
 * Three questions, all from the build plan's stated test list. Does a wrong secret get
 * refused. Does revalidation fire on a version and never on a rejected proposal. And
 * does a slow or broken client leave the request that created the version untouched.
 *
 * The third is the one worth the most, because it is the failure that would only ever
 * appear in production, on the day the client happened to be redeploying while a seller
 * happened to be resolving a proposal.
 */
function revalidationProduct(): Product
{
    $product = Product::factory()->create([
        'name' => 'Meridian Desk Lamp DL-7',
        'slug' => 'meridian-desk-lamp-dl-7',
        'category' => 'Lighting',
        'specifications' => ['output' => '800 lumens'],
    ]);

    ProductAttribute::create([
        'product_id' => $product->id,
        'name' => 'Finish',
        'options' => ['Brass', 'Black'],
        'position' => 0,
    ]);

    Variant::factory()->for($product)->combination(['Finish' => 'Brass'])->create();
    Variant::factory()->for($product)->combination(['Finish' => 'Black'])->create();

    return $product->refresh();
}

/** Turns EP-51 on for one test. It is off by default in phpunit.xml. */
function enableRevalidation(string $secret = 'test-secret'): void
{
    config()->set('frontend.revalidation.enabled', true);
    config()->set('frontend.revalidation.secret', $secret);
    config()->set('frontend.url', 'http://localhost:3000');
}

/*
|--------------------------------------------------------------------------
| The secret
|--------------------------------------------------------------------------
*/

it('sends the configured secret as the x-revalidate-secret header', function (): void {
    enableRevalidation('the-real-secret');
    Http::fake(['*' => Http::response(['data' => ['revalidated' => true]], 200)]);

    (new RevalidateProductPage('meridian-desk-lamp-dl-7'))->handle();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://localhost:3000/api/revalidate'
            && $request->header('x-revalidate-secret') === ['the-real-secret']
            && $request->data() === ['slug' => 'meridian-desk-lamp-dl-7'];
    });
});

it('treats the client refusing a wrong secret as a failure worth retrying', function (): void {
    /*
     * The client answers 401 when the header does not match its own REVALIDATE_SECRET.
     * The job has to treat that as a failure rather than shrugging, because the usual
     * cause is the two sides holding different secrets mid deployment, and the attempt
     * after that will succeed. A job that swallowed the 401 would leave the page stale
     * forever with nothing recorded anywhere.
     */
    enableRevalidation('the-wrong-secret');
    Http::fake(['*' => Http::response(['code' => 'unauthenticated'], 401)]);

    expect(fn () => (new RevalidateProductPage('meridian-desk-lamp-dl-7'))->handle())
        ->toThrow(RuntimeException::class, '401');
});

it('does nothing at all when no secret is configured', function (): void {
    /*
     * A deployment fault, not a transient one. Retrying it five times produces five
     * identical failures and buries the cause, so it is logged once and the job ends.
     */
    config()->set('frontend.revalidation.enabled', true);
    config()->set('frontend.revalidation.secret', null);
    Http::fake();

    (new RevalidateProductPage('meridian-desk-lamp-dl-7'))->handle();

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Fires on a version, and on nothing else
|--------------------------------------------------------------------------
*/

it('dispatches revalidation when a version is created', function (): void {
    Queue::fake();

    $product = revalidationProduct();

    app(ProductVersionService::class)->record($product);

    Queue::assertPushed(
        RevalidateProductPage::class,
        fn (RevalidateProductPage $job): bool => $job->slug === 'meridian-desk-lamp-dl-7',
    );
});

it('never dispatches revalidation for a rejected proposal', function (): void {
    /*
     * The invariant this endpoint hangs off. A rejected proposal writes no version and
     * changes no product data, so there is nothing for a client to rebuild. If this
     * ever fires, the client is being told to rebuild a page around a change that was
     * refused.
     */
    Queue::fake();

    $product = revalidationProduct();
    $proposer = Store::factory()->for(User::factory())->create();
    $reviewers = [
        Store::factory()->for(User::factory())->create(),
        Store::factory()->for(User::factory())->create(),
    ];

    $proposal = Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => ['output' => ['from' => '800 lumens', 'to' => '1200 lumens']],
        // Low confidence with peers against is the row that rejects outright.
        'confidence_band' => Proposal::BAND_LOW,
        'confidence_score' => 0.4,
        'intended_variant_ids' => [$product->variants()->first()->id],
        'intended_price_minor' => 450_000,
        'intended_currency' => 'LKR',
    ]);

    foreach ($reviewers as $reviewer) {
        ProposalReviewer::create(['proposal_id' => $proposal->id, 'store_id' => $reviewer->id]);
    }

    foreach ($reviewers as $reviewer) {
        $proposal->votes()->create([
            'store_id' => $reviewer->id,
            // A boolean column: false is against. There is no third value, because an
            // absent row is what makes a reviewer a non voter.
            'vote' => false,
            'comment' => 'The output is unchanged on this revision.',
        ]);
    }

    $resolved = app(ProposalResolutionService::class)->resolveIfReady($proposal->refresh());

    expect($resolved->status)->toBe(Proposal::STATUS_REJECTED)
        ->and($product->versions()->count())->toBe(0);

    Queue::assertNotPushed(RevalidateProductPage::class);
});

/*
|--------------------------------------------------------------------------
| A slow client must not cost anybody their write
|--------------------------------------------------------------------------
*/

it('creates the version even when the client never answers', function (): void {
    /*
     * The build plan's third stated test, and the reason this is a queued job dispatched
     * after commit rather than an inline HTTP call.
     *
     * The queue is switched to the database driver for this one test, deliberately. The
     * suite runs on `sync`, where every dispatch executes inside the caller, and on
     * `sync` a client that hangs really would hang the request. That is a property of
     * the test driver, not of the design, and asserting against it would prove the
     * opposite of what this test is for. The database driver is what runs locally and
     * what production runs, so this exercises the real shape: the version is written,
     * the work is handed to the queue, and the request is finished before anybody has
     * spoken to the client at all.
     */
    config()->set('queue.default', 'database');
    enableRevalidation();

    // Fails on contact, which is what a redeploying or unreachable Next.js server looks
    // like from here. Nothing should ever reach it during this request.
    Http::fake(fn () => throw new ConnectionException('Connection timed out after 5000 ms'));

    $product = revalidationProduct();

    $version = app(ProductVersionService::class)->record($product);

    expect($version->exists)->toBeTrue()
        ->and($version->version_number)->toBe(1)
        ->and($product->refresh()->current_version_id)->toBe($version->id)
        ->and($product->versions()->count())->toBe(1);

    // Waiting on a worker rather than already attempted, which is the whole point.
    Http::assertNothingSent();

    expect(DB::table('jobs')->where('queue', 'revalidation')->count())->toBe(1);
});

it('leaves the version alone when the job itself gives up', function (): void {
    /*
     * The other half of the same question. The request survived above because the work
     * was deferred; this asserts that the work failing later costs the version nothing
     * either. There is no compensating action and there should not be: the version is
     * correct, and the only casualty is a client page serving an older render until its
     * own time based revalidation catches up.
     */
    config()->set('queue.default', 'database');
    enableRevalidation();
    Http::fake(['*' => Http::response(['code' => 'server_error'], 500)]);

    $product = revalidationProduct();
    $version = app(ProductVersionService::class)->record($product);

    expect(fn () => (new RevalidateProductPage($product->slug))->handle())
        ->toThrow(RuntimeException::class, '500');

    expect($product->refresh()->current_version_id)->toBe($version->id)
        ->and($product->versions()->count())->toBe(1)
        ->and($product->fresh()->name)->toBe('Meridian Desk Lamp DL-7');
});

it('records that it gave up rather than raising anything a caller could see', function (): void {
    enableRevalidation();

    $job = new RevalidateProductPage('meridian-desk-lamp-dl-7');

    // `failed` is the end of the line after every retry. It must not throw, because
    // nothing is left to catch it and there is nothing to undo.
    $job->failed(new RuntimeException('Revalidating failed with status 500.'));
})->throwsNoExceptions();

it('sends the revalidation on its own queue, away from the work sellers are blocked on', function (): void {
    /*
     * The queue name is not decoration. It is what stops an unreachable client putting
     * the review window sweep behind a backlog of timeouts, and the Horizon supervisor
     * split depends on it.
     */
    expect((new RevalidateProductPage('meridian-desk-lamp-dl-7'))->queue)->toBe('revalidation');
});
