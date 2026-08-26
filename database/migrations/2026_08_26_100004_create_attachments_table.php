<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's declaration that it carries a variant, at a price.
 *
 * The highest cardinality table in the system, and the one the seller list query runs
 * against on the highest traffic page. Its indexes are chosen for that query.
 *
 * A row exists only once a proposal resolves as approved, or immediately where the
 * confirmation flow produced no changes. While a proposal is pending, the absence of a
 * row here is what blocks the proposing seller from selling that product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();

            /*
             * Denormalised deliberately. This table reaches a product through variants,
             * so the column is redundant, but the seller list query filters by product
             * on every product page request. Without it that query joins to variants
             * every time, on the most requested page in the system.
             *
             * The value cannot drift, because a variant is never reassigned to another
             * product.
             */
            $table->foreignId('product_id')->constrained();

            // Integer in the smallest currency unit. The API never emits or accepts a
            // decimal price, and division for display happens in the client only.
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('LKR');

            // Marking a variant unavailable keeps the row. It drops out of availability
            // filters, but the store stays live.
            $table->boolean('is_available')->default(true);

            $table->timestamps();

            $table->unique(['store_id', 'variant_id']);
            $table->index(['product_id', 'is_available']);
            $table->index(['variant_id', 'price_minor']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
