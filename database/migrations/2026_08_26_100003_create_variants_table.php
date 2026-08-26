<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated variant combinations. Permanent, and never removed by anyone.
 *
 * There is no deleted_at here and no deletion path anywhere in the application, not
 * even for an administrator. A combination no seller carries simply has no attachments
 * rows, and the product page renders it as having no sellers yet rather than hiding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // The combination itself, for example {"Colour":"Black","Capacity":"128GB"}.
            $table->jsonb('attribute_values');

            /*
             * A deterministic hash of the sorted attribute values, existing solely to
             * support the unique constraint below. PostgreSQL cannot enforce uniqueness
             * on JSONB content in a way that ignores key ordering, and two combinations
             * differing only in key order are the same combination.
             */
            $table->string('combination_hash', 64);

            // True for the single default variant of a product with no attributes.
            $table->boolean('is_default')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'combination_hash']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
