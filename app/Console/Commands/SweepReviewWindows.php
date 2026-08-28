<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResolveExpiredReviewWindows;
use Illuminate\Console\Command;

/**
 * Runs the review window sweep here and now, without waiting for a worker.
 *
 * The work lives in [ResolveExpiredReviewWindows]. This command exists so an operator
 * can force a sweep and watch what it decides, which is the first thing anybody does
 * when a seller reports being stuck. Running it inline rather than dispatching it means
 * the output appears in the terminal rather than in a worker's log, and it works on a
 * machine with no queue worker running at all.
 *
 * The schedule dispatches the job instead, so the routine path is queued and visible in
 * Horizon and the manual path is immediate. Both run the same code.
 */
final class SweepReviewWindows extends Command
{
    protected $signature = 'proposals:sweep';

    protected $description = 'Resolve proposals whose review window has closed';

    public function handle(): int
    {
        $job = new ResolveExpiredReviewWindows;

        /*
         * Called through the container rather than dispatched.
         *
         * `dispatch_sync` looks like the right thing and is not: it hands the job to the
         * sync queue connection, which serialises it and runs a copy, so everything the
         * run recorded about itself is lost with that copy. Calling `handle` through the
         * container resolves its dependencies exactly as a worker would and leaves the
         * results on the instance, which is what this command exists to print.
         */
        $this->laravel->call([$job, 'handle']);

        foreach ($job->resolutions as $line) {
            $this->line($line);
        }

        $this->info($job->resolutions === []
            ? 'No review windows had closed.'
            : 'Resolved '.count($job->resolutions).' proposal(s).');

        return self::SUCCESS;
    }
}
