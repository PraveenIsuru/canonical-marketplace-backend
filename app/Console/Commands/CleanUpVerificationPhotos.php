<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeleteOrphanedVerificationPhotographs;
use Illuminate\Console\Command;

/**
 * Runs the verification photograph sweep here and now.
 *
 * The work lives in [DeleteOrphanedVerificationPhotographs]. This command exists so the
 * sweep can be run on demand with a different age threshold, which is what somebody
 * does when they have found a stray photograph and want it gone rather than waiting for
 * tonight's run.
 *
 * The schedule dispatches the job instead, so the routine path is queued and visible in
 * Horizon and the manual path is immediate. Both run the same code.
 */
final class CleanUpVerificationPhotos extends Command
{
    protected $signature = 'verification:cleanup {--hours=6 : How old an orphan must be}';

    protected $description = 'Delete verification photographs left behind by interrupted work';

    public function handle(): int
    {
        $job = new DeleteOrphanedVerificationPhotographs((int) $this->option('hours'));

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

        $this->info($job->removed === 0
            ? 'No verification photographs needed removing.'
            : "Removed {$job->removed} verification photograph(s) left behind by interrupted work.");

        return self::SUCCESS;
    }
}
