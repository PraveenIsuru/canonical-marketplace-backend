<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images belonging to the canonical record.
 *
 * They belong to the product, not to a seller, and are shared by every store carrying
 * it. There is no moderation status column, because images publish immediately.
 *
 * The ceiling of eight images per product is enforced in application logic, since a
 * row count limit is not expressible as a column constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('storage_path', 500);

            // One of image/jpeg, image/png, image/webp. Validated at upload.
            $table->string('mime_type', 50);

            // Maximum 5242880 bytes. Validated at upload rather than constrained here,
            // so the failure is a readable validation error rather than a database one.
            $table->integer('file_size_bytes');

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('position')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
