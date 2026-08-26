<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peer review votes.
 *
 * No updated_at and no soft delete. Votes are immutable once cast.
 *
 * The vote is a single boolean because a proposal is accepted or rejected as a whole.
 * There is no field level accept or reject anywhere in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->boolean('vote');
            $table->text('comment')->nullable();

            $table->timestamp('created_at')->useCurrent();

            /*
             * Prevents double voting. Eligibility itself is not enforceable here: review
             * rights belong to stores attached when the proposal opened, and attachments
             * change during the three day window, so a point in time membership rule is
             * something no foreign key can express. It is checked in application logic.
             */
            $table->unique(['proposal_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_votes');
    }
};
