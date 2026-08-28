<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Proposal;
use App\Services\Proposals\ProposalResolutionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resolves proposals whose three day review window has run out.
 *
 * This is the most important recurring work in the platform, and it is worth being
 * blunt about why. A proposal that receives no votes must escalate, and **the proposing
 * seller is blocked from selling that product until it resolves**. If this stops
 * running, sellers quietly stay blocked, nothing else in the system notices, and the
 * only symptom is a person who cannot trade and has no way to find out why.
 *
 * Every resolution goes through the same matrix the vote endpoint uses, so a proposal
 * that expires and one that completes by voting cannot be decided differently.
 *
 * ## Why this is a queued job at M12 and was a console command before
 *
 * The work is identical. What changed is that it now runs on a queue Horizon watches,
 * so a failure lands in the failed jobs list and a backlog shows up as a wait time
 * instead of being a line in a log file nobody is reading. The `proposals:sweep`
 * command still exists and still does exactly this, by calling the job inline, because
 * an operator wanting to force a sweep should not have to have a worker running.
 */
final class ResolveExpiredReviewWindows implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One attempt.
     *
     * Retrying is the wrong instinct here. Each proposal is resolved in its own
     * transaction, so a run that fails halfway has already committed the ones before
     * it, and a retry would sweep again from the top. The next scheduled run picks up
     * whatever was left, an hour later, which is the same thing a retry would do with
     * less chance of two sweeps overlapping.
     */
    public int $tries = 1;

    /**
     * Never two sweeps at once.
     *
     * This used to be `withoutOverlapping` on the schedule entry, which stopped a slow
     * run having a second one start beside it. Dispatching to a queue moved the risk
     * rather than removing it: the dispatch itself is instant, so the scheduler can no
     * longer overlap, but two sweeps could still sit in a backlog and be picked up by
     * two workers at the same moment.
     *
     * Two sweeps resolving the same proposal is exactly the race the row lock in the
     * resolution service defends against, and not starting the race is cheaper than
     * winning it. The lock is released after an hour regardless, so a worker killed
     * mid sweep cannot leave the next one blocked forever.
     */
    public int $uniqueFor = 3600;

    /**
     * What the run did, readable afterwards by whoever dispatched it.
     *
     * @var array<int, string>
     */
    public array $resolutions = [];

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ProposalResolutionService $resolution): void
    {
        $expired = Proposal::query()
            ->where('status', Proposal::STATUS_PENDING)
            ->where('review_closes_at', '<=', now())
            // Oldest first, so a backlog after an outage clears in the order sellers
            // have been waiting rather than in whatever order the index returns.
            ->orderBy('review_closes_at')
            ->get();

        foreach ($expired as $proposal) {
            /*
             * Each one in its own transaction, inside the service. One proposal that
             * fails to apply must not roll back the others, because they are unrelated
             * and every one left pending is another seller still blocked.
             */
            $resolved = $resolution->resolveIfReady($proposal, windowHasClosed: true);

            $this->resolutions[] = "Proposal {$resolved->id}: {$resolved->status} ({$resolved->resolution_reason})";
        }
    }
}
