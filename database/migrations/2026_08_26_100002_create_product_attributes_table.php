<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute definitions, defined per product rather than globally.
 *
 * A product with no meaningful variation has zero rows here and exactly one default
 * variant. Attribute names are meaningful only within their own product, which is why
 * there is no shared attribute vocabulary table to join against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);

            // An ordered array of option values, for example ["Black", "White"].
            // Order matters, because it drives both display and combination generation.
            $table->jsonb('options');

            $table->smallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
