<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own columns on the users table.
 *
 * `is_admin` is a flag rather than a separate identity, because one account may hold
 * buyer and seller roles at the same time and an administrator is simply a user with
 * this set. There is no roles table and no roles array.
 *
 * The coordinates are what nearby availability alerts are calculated against. A user
 * with no location receives no proximity alerts, which is correct rather than a
 * failure, so both columns are nullable.
 *
 * The geography point is derived from the two columns in the same write, so the pair
 * and the point can never disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
            $table->decimal('latitude', 10, 7)->nullable()->after('is_admin');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->softDeletes();
        });

        // PostGIS geography, so distance is computed by the database rather than in PHP.
        DB::statement('ALTER TABLE users ADD COLUMN location geography(Point, 4326) NULL');
        DB::statement('CREATE INDEX users_location_index ON users USING GIST (location)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_location_index');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS location');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['is_admin', 'latitude', 'longitude']);
        });
    }
};
