<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The version chain, which is also the audit record.
 *
 * Rows exist for accepted proposals and administrator edits, and for nothing else. A
 * rejected proposal produces no row here and is traceable through proposals alone.
 *
 * No updated_at and no soft delete. Versions are immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('version_number');

            /*
             * The complete record state at this version, not the fields that changed.
             *
             * Diffs would be smaller, but reconstructing a version would mean replaying
             * the chain from the beginning. A snapshot makes retrieval a single row
             * read, and since proposals are all or nothing, a version boundary always
             * corresponds to a coherent whole record state anyway.
             */
            $table->jsonb('snapshot');

            // Null where an administrator edited directly rather than a proposal landing.
            $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('caused_by_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('caused_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_admin_originated')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'version_number']);
        });

        /*
         * The pointer to the newest version.
         *
         * Added here rather than on the products table itself because the two tables
         * reference each other. The cycle is resolved at write time by creating the
         * product with a null pointer, then the version, then updating the pointer.
         *
         * The alternative, finding the newest version by MAX(version_number) on every
         * read, turns a direct lookup into an aggregate on the read path.
         */
        Schema::table('products', function (SchemaBlueprint $table): void {
            $table->foreignId('current_version_id')
                ->nullable()
                ->after('specifications')
                ->constrained('product_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (SchemaBlueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::dropIfExists('product_versions');
    }
};
