<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Proposal;
use App\Services\Proposals\ProposalResolutionService;
use Illuminate\Console\Command;

/**
 * Resolves proposals whose three day review window has run out.
 *
 * This job matters more than its size suggests. A proposal that receives no votes must
 * escalate, and the proposing seller is blocked from selling the product until it
 * resolves, so **a missed run leaves a seller unable to trade**. It is not best effort
 * work: if this stops running, sellers quietly stay blocked and nothing else in the
 * platform notices.
 *
 * Every resolution goes through the same matrix the vote endpoint uses, so a proposal
 * that expires and one that completes by voting cannot be decided differently.
 */
final class SweepReviewWindows extends Command
{
    protected $signature = 'proposals:sweep';

    protected $description = 'Resolve proposals whose review window has closed';

    public function handle(ProposalResolutionService $resolution): int
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

            $this->line("Proposal {$resolved->id}: {$resolved->status} ({$resolved->resolution_reason})");
        }

        $this->info($expired->isEmpty()
            ? 'No review windows had closed.'
            : "Resolved {$expired->count()} proposal(s).");

        return self::SUCCESS;
    }
}
