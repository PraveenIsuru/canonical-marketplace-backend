<?php

declare(strict_types=1);

use App\Jobs\DeleteOrphanedVerificationPhotographs;
use App\Jobs\ReconcileStoreLiveFlags;
use App\Jobs\ResolveExpiredReviewWindows;
use App\Jobs\RevalidateProductPage;
use App\Models\Attachment;
use App\Models\Product;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Store;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\MaintenanceHealthAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * M12 recurring work: reconciliation, and the monitoring around it.
 *
 * Two things are being asserted here, and they are different in kind.
 *
 * The reconciliation job is ordinary code with an ordinary test. Given a flag that does
 * not match the attachments underneath it, does it correct the flag.
 *
 * The health check is stranger, because what it asserts is that the platform notices
 * when something has stopped happening. Each of those tests builds the state a broken
 * system would leave behind and asks whether anybody would find out.
 */
function maintenanceProduct(): Product
{
    $product = Product::factory()->create([
        'name' => 'Ardent Floor Fan F-9',
        'category' => 'Climate',
    ]);

    Variant::factory()->for($product)->combination([])->create();

    return $product->refresh();
}

function maintenanceStoreWithStock(): Store
{
    $product = maintenanceProduct();
    $store = Store::factory()->for(User::factory())->create();

    Attachment::factory()->create([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'variant_id' => $product->variants()->first()->id,
        'price_minor' => 15_000,
        'currency' => 'LKR',
        'is_available' => true,
    ]);

    return $store->refresh();
}

/*
|--------------------------------------------------------------------------
| Live flag reconciliation
|--------------------------------------------------------------------------
*/

it('turns a store dark when a bulk delete took its last attachment', function (): void {
    /*
     * The hole M8 left open, and the reason this job exists.
     *
     * Attachments are supposed to be deleted through the model, because the model event
     * is what recomputes the flag. A bulk delete fires no events, which is exactly what
     * is simulated here: the row goes through the query builder, the flag is left
     * behind, and a shop with nothing on its shelves keeps appearing to buyers.
     */
    $store = maintenanceStoreWithStock();

    expect($store->is_live)->toBeTrue();

    DB::table('attachments')->where('store_id', $store->id)->delete();

    expect($store->refresh()->is_live)->toBeTrue('the bulk delete should have left the flag stale');

    $job = new ReconcileStoreLiveFlags;
    $this->app->call([$job, 'handle']);

    expect($store->refresh()->is_live)->toBeFalse()
        ->and($job->corrections)->toHaveCount(1)
        ->and($job->corrections[0]['was'])->toBeTrue()
        ->and($job->corrections[0]['now'])->toBeFalse();
});

it('turns a store live again when a bulk insert gave it stock', function (): void {
    /*
     * The opposite direction, and the worse of the two. A store marked dark while
     * holding stock is a seller who is invisible and losing business without knowing
     * it, and nothing in the platform would ever tell them.
     */
    $store = maintenanceStoreWithStock();
    $product = maintenanceProduct();

    DB::table('attachments')->where('store_id', $store->id)->delete();
    $store->refresh()->forceFill(['is_live' => false])->save();

    DB::table('attachments')->insert([
        'store_id' => $store->id,
        'product_id' => $product->id,
        'variant_id' => $product->variants()->first()->id,
        'price_minor' => 15_000,
        'currency' => 'LKR',
        'is_available' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($store->refresh()->is_live)->toBeFalse();

    $this->app->call([new ReconcileStoreLiveFlags, 'handle']);

    expect($store->refresh()->is_live)->toBeTrue();
});

it('corrects nothing when every flag already matches', function (): void {
    maintenanceStoreWithStock();

    $job = new ReconcileStoreLiveFlags;
    $this->app->call([$job, 'handle']);

    expect($job->corrections)->toBe([]);
});

it('leaves soft deleted stores alone', function (): void {
    /*
     * A deleted store is excluded from every catalogue query already, so its flag has no
     * effect on anything a buyer sees. Correcting it would add noise to a log whose
     * whole value is that a line in it means something went wrong.
     */
    $store = maintenanceStoreWithStock();
    DB::table('attachments')->where('store_id', $store->id)->delete();
    $store->delete();

    $job = new ReconcileStoreLiveFlags;
    $this->app->call([$job, 'handle']);

    expect($job->corrections)->toBe([]);
});

it('reports what it changed through the command', function (): void {
    $store = maintenanceStoreWithStock();
    DB::table('attachments')->where('store_id', $store->id)->delete();

    $this->artisan('stores:reconcile-live')
        ->expectsOutputToContain('became dark')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| The health check
|--------------------------------------------------------------------------
*/

it('passes quietly when nothing is overdue', function (): void {
    Notification::fake();
    maintenanceStoreWithStock();

    $this->artisan('maintenance:health')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('finds a seller left blocked past their review window', function (): void {
    /*
     * The fault that matters most, and the one the build plan singles out. A proposal
     * past its window that has not resolved is a seller who cannot sell that product
     * and has no automatic route out, because escalation is the route and escalation is
     * what has not happened.
     *
     * Note what this test does **not** do: it never says the sweep failed. It builds the
     * state a failed sweep leaves behind. That is deliberate, because the same state is
     * left by a stopped scheduler, a dead worker, a queue pointed at nothing, and a bug
     * in the matrix, and the seller is equally stuck in all four.
     */
    Notification::fake();

    $administrator = User::factory()->create(['is_admin' => true]);
    $product = maintenanceProduct();
    $proposer = Store::factory()->for(User::factory())->create();

    $proposal = Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => ['weight' => ['from' => '1.3 kg', 'to' => '1.24 kg']],
        'confidence_band' => Proposal::BAND_HIGH,
        'confidence_score' => 0.9,
        'intended_variant_ids' => [$product->variants()->first()->id],
        'intended_price_minor' => 431_000,
        'intended_currency' => 'LKR',
    ]);

    ProposalReviewer::create([
        'proposal_id' => $proposal->id,
        'store_id' => Store::factory()->for(User::factory())->create()->id,
    ]);

    // Well past the three hour grace, so this is a fault rather than an ordinary wait.
    $proposal->forceFill(['review_closes_at' => now()->subDays(2)])->save();

    $this->artisan('maintenance:health')
        ->expectsOutputToContain('past their review window')
        ->assertExitCode(1);

    Notification::assertSentTo($administrator, MaintenanceHealthAlert::class);
});

it('does not report a proposal that is still inside its window', function (): void {
    Notification::fake();

    $product = maintenanceProduct();
    $proposer = Store::factory()->for(User::factory())->create();

    Proposal::factory()->for($product)->for($proposer)->create([
        'changes' => ['weight' => ['from' => '1.3 kg', 'to' => '1.24 kg']],
        'confidence_band' => Proposal::BAND_HIGH,
        'confidence_score' => 0.9,
        'intended_variant_ids' => [$product->variants()->first()->id],
        'intended_price_minor' => 431_000,
        'intended_currency' => 'LKR',
        'review_closes_at' => now()->addDay(),
    ]);

    $this->artisan('maintenance:health')->assertExitCode(0);

    Notification::assertNothingSent();
});

it('finds a live flag that has drifted, and does not quietly repair it', function (): void {
    /*
     * Read only on purpose. Reconciliation is a separate job with its own schedule, and
     * a check that silently fixed what it found would hide how often the flag drifts,
     * which is the interesting part. A drift means an attachment changed without a model
     * event, and that is worth tracing rather than papering over.
     */
    Notification::fake();
    User::factory()->create(['is_admin' => true]);

    $store = maintenanceStoreWithStock();
    DB::table('attachments')->where('store_id', $store->id)->delete();

    $this->artisan('maintenance:health')
        ->expectsOutputToContain('do not match their attachments')
        ->assertExitCode(1);

    expect($store->refresh()->is_live)->toBeTrue('the check must report, not repair');
});

it('can report without emailing anybody', function (): void {
    Notification::fake();
    User::factory()->create(['is_admin' => true]);

    $store = maintenanceStoreWithStock();
    DB::table('attachments')->where('store_id', $store->id)->delete();

    $this->artisan('maintenance:health --no-notify')->assertExitCode(1);

    Notification::assertNothingSent();
});

it('sends the alert to a configured address instead of every administrator', function (): void {
    Notification::fake();
    User::factory()->create(['is_admin' => true]);
    config()->set('maintenance.health.notify', 'operations@example.test');

    $store = maintenanceStoreWithStock();
    DB::table('attachments')->where('store_id', $store->id)->delete();

    $this->artisan('maintenance:health')->assertExitCode(1);

    Notification::assertSentOnDemand(MaintenanceHealthAlert::class);
});

/*
|--------------------------------------------------------------------------
| The queues Horizon is configured to watch
|--------------------------------------------------------------------------
*/

it('puts every queued job on a queue a Horizon supervisor actually watches', function (): void {
    /*
     * The drift this catches is silent and total. A job dispatched to a queue no
     * supervisor is listening to is never processed at all, and nothing raises anything:
     * the dispatch succeeds, the row sits in the queue, and the work simply never
     * happens. Renaming a queue in a job and forgetting the configuration, or the other
     * way round, is an easy mistake and an invisible one.
     */
    $watched = collect(config('horizon.defaults'))
        ->flatMap(fn (array $supervisor): array => $supervisor['queue'])
        ->unique();

    $used = collect([
        new ResolveExpiredReviewWindows,
        new DeleteOrphanedVerificationPhotographs,
        new ReconcileStoreLiveFlags,
        new RevalidateProductPage('a-slug'),
    ])->map(fn (object $job): string => $job->queue ?? 'default')->unique();

    expect($used->diff($watched)->all())->toBe([]);
});

it('keeps the work sellers are blocked on away from the work that talks to the client', function (): void {
    /*
     * The supervisor split is not decoration. An unreachable client can produce a long
     * backlog of revalidation attempts, and if those shared a supervisor with the review
     * window sweep, a seller waiting to be unblocked would queue behind a cache
     * invalidation. That is the wrong way round, so they are separated and this asserts
     * they stay separated.
     */
    $supervisors = collect(config('horizon.defaults'));

    $carryingMaintenance = $supervisors->filter(
        fn (array $supervisor): bool => in_array('maintenance', $supervisor['queue'], true)
    );

    $carryingRevalidation = $supervisors->filter(
        fn (array $supervisor): bool => in_array('revalidation', $supervisor['queue'], true)
    );

    expect($carryingMaintenance)->not->toBeEmpty()
        ->and($carryingRevalidation)->not->toBeEmpty()
        ->and($carryingMaintenance->keys()->intersect($carryingRevalidation->keys())->all())->toBe([]);
});

it('gives every queue an explicit wait threshold, loosest where nobody is waiting', function (): void {
    /*
     * A queue with no threshold of its own is a queue nothing will ever raise a long
     * wait for, so each one is named.
     *
     * The ordering is the interesting part and it is not the ordering of importance.
     * `default` alarms soonest because a person is watching a spinner. `maintenance`
     * carries the sweep, which matters more and is hourly, so a ninety second wait on it
     * means nothing and alarming on that would train somebody to ignore the alert.
     * `revalidation` is loosest because nobody is waiting on it at all.
     */
    $waits = config('horizon.waits');
    $queues = collect(config('horizon.defaults'))->flatMap(fn (array $s): array => $s['queue'])->unique();

    foreach ($queues as $queue) {
        expect($waits)->toHaveKey("redis:{$queue}");
    }

    expect($waits['redis:default'])->toBeLessThan($waits['redis:maintenance'])
        ->and($waits['redis:maintenance'])->toBeLessThan($waits['redis:revalidation']);
});
