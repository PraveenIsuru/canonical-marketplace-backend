<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical product record. One row per real world product.
 *
 * Note what is absent: there is no deleted_at. Products are never deleted, because a
 * product with no sellers stays visible in the catalogue rather than disappearing.
 *
 * current_version_id is added by a later migration, once product_versions exists. The
 * two tables reference each other, which is resolved by creating the product with a
 * null pointer, then the version, then updating the pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 500);
            $table->string('slug', 500)->unique();
            $table->text('description')->nullable();
            $table->string('category', 100);

            // Structured facts gathered by the wizard. JSONB because the shape differs
            // from product to product and no fixed column set could hold it.
            $table->jsonb('specifications')->default('{}');

            /*
             * Historical attribution only. It conveys no ownership and no editing
             * rights, and it is never serialised to any client. Nullable so a product
             * survives deletion of the store whose wizard run created it.
             */
            $table->foreignId('created_by_store_id')->nullable()->constrained('stores')->nullOnDelete();

            $table->timestamps();

            $table->index('category');
        });

        // GIN over specifications keeps JSONB containment queries indexed.
        DB::statement('CREATE INDEX products_specifications_index ON products USING GIN (specifications)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
