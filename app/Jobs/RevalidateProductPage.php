<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * EP-51. Tells the client that a product page is out of date.
 *
 * The client renders product pages statically, so a page it built yesterday keeps
 * being served until something tells it otherwise. That something is this job. It
 * POSTs the product's slug to the client's revalidation webhook, which rebuilds
 * `/products/{slug}` and `/products/{slug}/sellers`.
 *
 * **Dispatched on version creation and on nothing else.** Not on a rejected proposal,
 * not on a failed one, not on a price edit, not on a page view. Invariant 6 already
 * says a version exists only for an accepted proposal, an administrator edit, or the
 * wizard creating version 1, so hanging this off version creation rather than off any
 * particular controller is what makes "fires only on a version" true by construction
 * instead of true by everyone remembering.
 *
 * **Queued, and dispatched after the transaction commits.** Both matter, for different
 * reasons. After commit, because a rolled back version must not have told the client
 * to rebuild a page around a change that no longer exists. Queued, because the client
 * is a separate service over the network: a slow or unreachable one must never fail the
 * request that created the version. The version is committed either way, and a page
 * that rebuilds a few seconds late is a far smaller problem than a proposal resolution
 * that fails because a web server was restarting.
 *
 * Retries are worth having here. A page left stale is a buyer reading last week's
 * specifications, which the platform has no other way to notice or correct.
 */
final class RevalidateProductPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Widening backoff, ending at five minutes.
     *
     * A client that is down is usually down for longer than a few seconds, typically
     * because it is being deployed. Retrying hard would fill the queue with attempts
     * against a host that cannot answer any of them yet.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public readonly string $slug)
    {
        /*
         * Its own queue, away from everything else.
         *
         * This is the only job in the platform that waits on an external HTTP service
         * the platform does not control. Sharing a queue with the review window sweep
         * would let an unreachable client put sellers behind a backlog of timeouts,
         * and a seller waiting to be unblocked is the one thing that must not queue
         * behind a cache invalidation.
         */
        $this->onQueue('revalidation');
    }

    public function handle(): void
    {
        if (config('frontend.revalidation.enabled') !== true) {
            return;
        }

        $secret = config('frontend.revalidation.secret');

        /*
         * A missing secret is a deployment fault, not a transient one. Retrying it five
         * times would produce five identical failures and bury the actual cause, so it
         * is logged once and the job ends successfully. The page stays stale, which is
         * the honest consequence, and the log says why.
         */
        if (! is_string($secret) || $secret === '') {
            Log::warning('Revalidation skipped: no REVALIDATE_SECRET is configured.', [
                'slug' => $this->slug,
            ]);

            return;
        }

        $endpoint = config('frontend.url').config('frontend.revalidation.path');

        $response = Http::withHeaders(['x-revalidate-secret' => $secret])
            ->timeout((int) config('frontend.revalidation.timeout'))
            ->acceptJson()
            ->post($endpoint, ['slug' => $this->slug]);

        /*
         * Thrown rather than returned, so the queue's retry machinery sees a failure.
         * A 401 is worth retrying too: it usually means the two sides were mid
         * deployment with different secrets, and the attempt after that will succeed.
         */
        if ($response->failed()) {
            throw new RuntimeException(
                "Revalidating {$this->slug} failed with status {$response->status()}."
            );
        }
    }

    /**
     * The end of the line, after every retry.
     *
     * Recorded rather than escalated. There is nothing to undo: the version exists and
     * is correct, and the only casualty is a client page serving an older render of it
     * until its own time based revalidation catches up. That is a log line, not an
     * email to an administrator.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Revalidation gave up.', [
            'slug' => $this->slug,
            'reason' => $exception->getMessage(),
        ]);
    }
}
