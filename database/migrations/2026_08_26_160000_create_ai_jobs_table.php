<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks AI work that was queued because the provider was unavailable.
 *
 * Not in the schema design document, which lists only the standard framework tables.
 * It is needed because the API contract promises a `queued_job_id` the client can poll
 * for a status and a result, and Laravel's own `jobs` table deletes the row the moment
 * the work finishes. Polling it would return "not found" for every job that succeeded,
 * which is the opposite of what the client needs to know.
 *
 * The client persists the id to localStorage and resumes the blocked flow from the
 * result, so these rows must outlive the queue entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table): void {
            // A uuid rather than an incrementing id. The value is handed to a client
            // and polled back, so it must not let anyone enumerate other people's jobs.
            $table->uuid('id')->primary();

            // Nullable because buyer facing AI work can originate from an anonymous
            // visitor. A job with no user is readable by nobody, which is correct.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Which kind of AI call this was, matching the result_type the contract
            // defines: match_candidates, wizard_questions, confirmation_questions,
            // verification_result, and search_interpretation.
            $table->string('type', 40);

            $table->string('status', 20)->default('queued');

            // The input needed to retry the call, and the answer once it succeeds.
            $table->jsonb('payload');
            $table->jsonb('result')->nullable();

            $table->text('failure_reason')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
