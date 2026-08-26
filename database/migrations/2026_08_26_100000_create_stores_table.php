<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One store per seller.
 *
 * The unique constraint on user_id is what enforces that, and it is also what makes
 * "is this user a seller" answerable without a role column: a user is a seller if and
 * only if a row here references them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 100);

            // Public by design. The platform works on contact and redirect, so these
            // are returned to anonymous callers rather than hidden behind auth.
            $table->string('contact_email');
            $table->string('contact_phone', 50)->nullable();
            $table->string('address_line', 500);
            $table->string('city', 100);

            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            // Which path produced the coordinates. Manual placement is a fallback, and
            // distinguishing it supports later data quality review.
            $table->string('geocode_source', 20);

            $table->decimal('rating', 3, 2)->nullable();

            /*
             * Maintained by the application, not computed on read. A store is visible
             * only while it holds at least one attachment, and recomputing that per
             * product page render would mean a correlated subquery against the largest
             * table in the system on the highest traffic route.
             */
            $table->boolean('is_live')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_live');
            $table->index('city');
        });

        // Geography rather than geometry, so distance comes back in metres on the
        // sphere without the caller choosing a projection.
        DB::statement('ALTER TABLE stores ADD COLUMN location geography(Point, 4326) NOT NULL');
        DB::statement('CREATE INDEX stores_location_index ON stores USING GIST (location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
