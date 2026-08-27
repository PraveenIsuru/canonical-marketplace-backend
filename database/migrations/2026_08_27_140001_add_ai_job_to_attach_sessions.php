<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a session to the job that is finishing its submission.
 *
 * When the AI provider fails during confirmation submit, the work is queued and the
 * seller is handed a job id to poll. Without this column, a seller who submits again
 * while that job is outstanding would queue a second one, and two jobs completing the
 * same session would race to create the same attachment or a duplicate proposal.
 *
 * With it, a second submit finds the outstanding job and returns that same id, which
 * is also what the interface needs: it directs the seller to the submission already in
 * flight rather than creating another.
 *
 * Nullable and normally null. It is set only on the provider failure path, and cleared
 * when the job finishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attach_sessions', function (Blueprint $table): void {
            $table->uuid('ai_job_id')->nullable()->after('draft');
        });
    }

    public function down(): void
    {
        Schema::table('attach_sessions', function (Blueprint $table): void {
            $table->dropColumn('ai_job_id');
        });
    }
};
