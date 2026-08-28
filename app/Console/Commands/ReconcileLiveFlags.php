<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReconcileStoreLiveFlags;
use Illuminate\Console\Command;

/**
 * Runs the live flag reconciliation here and now, and says what it changed.
 *
 * The work lives in [ReconcileStoreLiveFlags]. This command exists because the output
 * is the interesting part: a reconciliation that corrects nothing is the expected
 * result, and one that corrects something is worth reading, since it means an
 * attachment changed by a route that fired no model event.
 */
final class ReconcileLiveFlags extends Command
{
    protected $signature = 'stores:reconcile-live';

    protected $description = 'Put every store visibility flag back in step with its attachments';

    public function handle(): int
    {
        $job = new ReconcileStoreLiveFlags;

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

        if ($job->corrections === []) {
            $this->info('Every store visibility flag already matched its attachments.');

            return self::SUCCESS;
        }

        foreach ($job->corrections as $correction) {
            $this->line(sprintf(
                'Store %d (%s): %s became %s',
                $correction['id'],
                $correction['name'],
                $correction['was'] ? 'live' : 'dark',
                $correction['now'] ? 'live' : 'dark',
            ));
        }

        $this->warn(sprintf(
            'Corrected %d store visibility flag(s). A flag out of step means an attachment '
            .'changed without a model event, which is worth tracing.',
            count($job->corrections),
        ));

        return self::SUCCESS;
    }
}
