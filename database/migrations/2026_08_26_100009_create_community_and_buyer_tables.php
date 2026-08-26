<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining buyer facing tables: verification, discussion, summaries, wishlist,
 * and view analytics.
 *
 * Grouped into one migration because they share no dependencies with each other and
 * splitting them would add five files without adding clarity.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Ownership verification, scoped per user per product.
         *
         * No column holds the photograph path. The photograph is deleted once
         * verification concludes, whether it passed or failed, and the path lives
         * transiently in the queued job payload only. photo_deleted_at records that
         * the deletion happened.
         */
        Schema::create('verification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('generated_code', 20);

            // Ceiling of five, counted per user per product. Exhausting the allowance
            // on one product leaves it untouched on every other.
            $table->smallInteger('attempt_number');

            $table->string('outcome', 20)->default('pending');

            // Retained after the photograph is destroyed, so a failure can still be
            // explained to the buyer.
            $table->text('ai_reasoning')->nullable();

            $table->timestampTz('photo_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'product_id']);
        });

        /*
         * Discussion, at product level. All variants share one community.
         *
         * There is no store or seller column. Seller participation is deferred, so a
         * store cannot author a post. A user who happens to run a store may still post
         * as a verified buyer, which is the single account model working as intended.
         */
        Schema::create('community_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();

            // Null for a top level post, set for a reply.
            $table->foreignId('parent_id')->nullable()->constrained('community_posts')->cascadeOnDelete();

            $table->text('body');
            $table->timestamps();

            // Administrator moderation soft deletes rather than removing, and a removed
            // post takes its replies with it.
            $table->softDeletes();

            $table->index(['product_id', 'created_at']);
            $table->index('parent_id');
        });

        // One summary per product, covering all variants together.
        Schema::create('community_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('summary_text');

            // Supports deciding when regeneration is worthwhile.
            $table->integer('post_count_at_generation');

            $table->timestampTz('generated_at');
            $table->timestamps();
        });

        // Saved at variant level, not product level, because a price alert is only
        // meaningful for a specific combination.
        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();

            // Guards against repeat alerts where a seller oscillates a price around a
            // threshold.
            $table->bigInteger('last_notified_price_minor')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'variant_id']);
        });

        /*
         * View analytics.
         *
         * user_id is nullable because the catalogue is fully public, so most views
         * carry no account at all. This table grows quickly; rollup aggregation is
         * expected but was not specified, so it is not modelled here.
         */
        Schema::create('product_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestampTz('viewed_at');

            $table->index(['product_id', 'viewed_at']);
            $table->index(['store_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_views');
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('community_summaries');
        Schema::dropIfExists('community_posts');
        Schema::dropIfExists('verification_attempts');
    }
};
