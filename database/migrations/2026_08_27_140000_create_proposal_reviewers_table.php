<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stores entitled to vote on a proposal, frozen at the moment it opened.
 *
 * Not in the schema design document, which says eligibility is "evaluated in
 * application logic against attachment state when the proposal opened". That describes
 * the rule correctly but cannot be implemented from the attachments table alone, and
 * the difference is the whole reason this table exists.
 *
 * Attachments change during the three day window. A store that attaches on day two
 * would look eligible to any query run on day three, and a store that detaches would
 * look ineligible even though its vote must stand. Neither is recoverable once the
 * moment has passed, so the set is written down when the proposal opens and never
 * recalculated.
 *
 * It also gives the reviewer notification somewhere to record itself, which matters
 * because a reviewer who was never emailed and a reviewer who ignored the email are
 * different situations and only one of them is a bug.
 *
 * No vote lives here. Votes are rows in proposal_votes, and the absence of one is what
 * makes a reviewer a non voter, who is excluded from the denominator at M7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_reviewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();

            /*
             * Not cascading on store deletion. A store that goes away later was still
             * an eligible reviewer at the time, and removing the row would quietly
             * shrink the denominator of a review that has already happened.
             */
            $table->foreignId('store_id')->constrained();

            $table->timestampTz('notified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // One row per store per proposal. The set is a set.
            $table->unique(['proposal_id', 'store_id']);

            // Drives "which proposals is this store expected to review", which is the
            // query EP-28 runs at M7.
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_reviewers');
    }
};
