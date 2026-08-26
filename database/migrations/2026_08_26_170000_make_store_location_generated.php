<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Derives stores.location from the coordinate pair in the database itself.
 *
 * Previously a model hook built the PostGIS point on every save. That worked, but it
 * was a convention: any write that bypassed Eloquent, or any future code path that
 * forgot, would leave the pair and the point disagreeing, and the seller list would
 * quietly sort by a stale position.
 *
 * A generated column makes the relationship structural instead. The point cannot
 * disagree with the coordinates because the database computes it, and nothing in PHP
 * builds spatial SQL by hand any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS stores_location_index');
        DB::statement('ALTER TABLE stores DROP COLUMN IF EXISTS location');

        DB::statement(<<<'SQL'
            ALTER TABLE stores
            ADD COLUMN location geography(Point, 4326)
            GENERATED ALWAYS AS (
                ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
            ) STORED
        SQL);

        DB::statement('CREATE INDEX stores_location_index ON stores USING GIST (location)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stores_location_index');
        DB::statement('ALTER TABLE stores DROP COLUMN IF EXISTS location');
        DB::statement('ALTER TABLE stores ADD COLUMN location geography(Point, 4326) NULL');
        DB::statement('CREATE INDEX stores_location_index ON stores USING GIST (location)');
    }
};
