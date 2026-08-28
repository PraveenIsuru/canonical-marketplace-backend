<?php

use App\Jobs\DeleteOrphanedVerificationPhotographs;
use App\Jobs\ReconcileStoreLiveFlags;
use App\Jobs\ResolveExpiredReviewWindows;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Recurring work
|--------------------------------------------------------------------------
|
| Every entry here dispatches a job rather than running a command in the scheduler's
| own process. That changed at M12 and the reason is worth recording.
|
| A command run by the scheduler executes inside the scheduler. If it throws, the
| scheduler logs it and moves on; if it hangs, it holds up the run; and either way the
| only trace is a line in a log file. A dispatched job lands on a queue Horizon watches,
| where a failure is a row in the failed jobs list that can be inspected and retried, a
| backlog is a visible wait time, and a slow run is a measured runtime rather than a
| guess.
|
| Both remain runnable by hand. `proposals:sweep`, `verification:cleanup`, and
| `stores:reconcile-live` run the same jobs inline, which is what an operator wants when
| they are chasing a specific complaint and do not want to wait for a worker.
|
| `onOneServer` throughout, so a deployment running more than one instance dispatches
| each of these once rather than once per machine.
*/

/*
 * The review window sweep. The most important line in this file.
 *
 * Hourly rather than daily, because the window closes at whatever time of day it
 * opened, and a daily run would leave a seller blocked for up to a further day after
 * their proposal was already decidable.
 *
 * This is not best effort work. A proposal that receives no votes must escalate, and
 * the proposing seller is blocked from selling until it resolves, so a missed run
 * leaves somebody unable to trade while nothing else in the platform notices.
 *
 * Horizon watches the queue it runs on, which catches the job throwing or backing up.
 * It cannot catch the job never being dispatched, because there is nothing to see. That
 * is what `maintenance:health` is for, and why it asks whether a seller is still blocked
 * rather than whether a job ran.
 */
Schedule::job(new ResolveExpiredReviewWindows)->hourly()->onOneServer();

/*
 * The verification photograph sweep.
 *
 * A safety net rather than the mechanism. Photographs are deleted the moment
 * verification concludes, and the queued job deletes them when the provider never
 * recovers. This catches what neither can: a worker killed between the upload and the
 * judgement, leaving a photograph with nothing coming back for it.
 *
 * The invariant is not that photographs are usually deleted. It is that one never
 * outlives its verification, and a process dying at the wrong moment is exactly the
 * case a guarantee has to survive.
 *
 * Daily is enough. The six hour age threshold, not the schedule, is what decides
 * whether a file is an orphan, so running more often would delete nothing sooner.
 */
Schedule::job(new DeleteOrphanedVerificationPhotographs)->daily()->onOneServer();

/*
 * Live flag reconciliation, new at M12.
 *
 * Invariant 12 is kept in step by model events on the attachment, and M8 added the rule
 * that attachments are deleted through the model and never in bulk, because a bulk
 * delete fires no events. A rule depends on everybody knowing it. This does not: it
 * reads what is actually there and corrects the flag either way, so a store cannot stay
 * dark while holding stock, or visible while holding none, because of a write that took
 * a shortcut.
 *
 * Daily, and it almost always corrects nothing. That is the expected result, and a run
 * that does correct something is worth investigating rather than celebrating, because
 * it means an attachment changed by a route that fired no event.
 */
Schedule::job(new ReconcileStoreLiveFlags)->daily()->onOneServer();

/*
 * The health check, new at M12.
 *
 * Run as a command rather than a job, on purpose and against the rule above. Its whole
 * job is to report that recurring work is not happening, and the most likely reason for
 * that is that the queue is not being worked. A health check sitting in the queue it is
 * meant to be reporting on would be the last thing to run and the first thing anybody
 * needed.
 *
 * Hourly, matching the sweep, since a blocked seller is the fault it exists to find.
 */
Schedule::command('maintenance:health')->hourly()->onOneServer();

/*
 * Horizon's own metrics snapshot, which is what fills the throughput and wait time
 * graphs on the dashboard. Without it Horizon runs perfectly well and shows flat lines,
 * which is a confusing way to find out that monitoring is half configured.
 *
 * Skipped when Horizon has no Redis to talk to, so a development machine running the
 * database queue driver does not accumulate a failed scheduled command every five
 * minutes for work it was never going to do.
 */
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->skip(fn (): bool => config('queue.default') !== 'redis');
