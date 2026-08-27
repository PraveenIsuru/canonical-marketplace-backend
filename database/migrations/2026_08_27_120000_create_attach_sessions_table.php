<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paused attachment flow: the questions asked, and the details they were asked about.
 *
 * Not in the schema design document, which describes the persistent domain and treats
 * a half finished flow as client state. It cannot be client state here. The endpoints
 * hand back a `session_id` the seller submits later, and the AI provider may be
 * unavailable at the moment the questions are wanted, so the flow has to survive both
 * a queued job finishing while nobody is looking and a browser restart.
 *
 * Holding the questions server side is also what makes "every question was answered"
 * checkable at all. A client that supplied both the questions and the answers could
 * always claim it answered them.
 *
 * `type` carries the wizard and the confirmation flow in one table. They differ in
 * which of `product_id` and `draft` is filled, not in structure, and two tables with
 * the same five columns would only duplicate the expiry and ownership rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attach_sessions', function (Blueprint $table): void {
            // A uuid rather than an incrementing id, for the same reason ai_jobs uses
            // one: the value is handed to a client, so it must not let anyone count
            // other sellers' sessions or guess at them.
            $table->uuid('id')->primary();

            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // wizard, or confirmation once M6 lands.
            $table->string('type', 20);

            /*
             * Null for a wizard session, because the product does not exist yet. That
             * is the whole difference between the two flows: confirmation questions a
             * record that is already there, and the wizard questions one being built.
             */
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();

            // The questions as they were put to the seller, so the answers can be
            // checked for completeness against what was actually asked.
            $table->jsonb('questions');

            // The details the seller entered before the questions were generated. Kept
            // so the submit step can be checked against what matching was run on.
            $table->jsonb('draft')->default('{}');

            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->index(['store_id', 'type']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attach_sessions');
    }
};
