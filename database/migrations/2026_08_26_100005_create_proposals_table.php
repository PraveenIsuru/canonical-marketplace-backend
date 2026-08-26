<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changes to a canonical record awaiting peer review.
 *
 * The only path by which a seller affects product data. No seller writes to products,
 * attributes, or variants directly, ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained();

            // Accepted or rejected as a whole. There is no field level review, so this
            // is one document rather than a set of independently votable changes.
            $table->jsonb('changes');

            // The raw seller answers from the confirmation flow, retained as evidence.
            $table->jsonb('ai_answers');

            /*
             * Scored by the AI from the content and consistency of the answers, never
             * self reported by the seller.
             *
             * Both of these must never appear in any response body, on any endpoint, at
             * any access level. They drive the resolution matrix server side, and
             * exposing them would anchor reviewer votes on the AI's assessment instead
             * of the reviewer's own knowledge of the product.
             */
            $table->decimal('confidence_score', 4, 3);
            $table->string('confidence_band', 10);

            $table->string('status', 20)->default('pending');

            $table->timestampTz('review_opens_at');

            // Exactly three days after opening. Null only once escalated, because no
            // deadline applies to an escalation.
            $table->timestampTz('review_closes_at');

            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_reason', 50)->nullable();

            // Set only where an administrator resolved it.
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Drives the scheduled expiry sweep.
            $table->index(['status', 'review_closes_at']);
            $table->index(['product_id', 'status']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
