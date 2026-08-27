<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a store exist before its coordinates are known.
 *
 * The schema design made latitude, longitude, and geocode_source NOT NULL, but the
 * store creation endpoint requires the opposite: when geocoding fails, the store is
 * created anyway with null coordinates and a 201, and the seller is routed into manual
 * pin placement.
 *
 * Two specifications describe that path explicitly, so the NOT NULL constraints are
 * what give. Returning a 4xx instead would discard details the seller correctly
 * submitted and turn a defined fallback into an error they did nothing to cause.
 *
 * `location` is a generated column derived from the pair, so it becomes null on its
 * own when they are. A store with no coordinates simply cannot appear in a proximity
 * sorted seller list, which is correct: it holds no attachments and is not live either.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stores ALTER COLUMN latitude DROP NOT NULL');
        DB::statement('ALTER TABLE stores ALTER COLUMN longitude DROP NOT NULL');
        DB::statement('ALTER TABLE stores ALTER COLUMN geocode_source DROP NOT NULL');
    }

    public function down(): void
    {
        /*
         * Restoring NOT NULL would fail against any store created through the geocoding
         * failure path, so those rows are dropped first. This only ever runs against a
         * development database being rolled back.
         */
        DB::statement('DELETE FROM stores WHERE latitude IS NULL OR longitude IS NULL OR geocode_source IS NULL');
        DB::statement('ALTER TABLE stores ALTER COLUMN latitude SET NOT NULL');
        DB::statement('ALTER TABLE stores ALTER COLUMN longitude SET NOT NULL');
        DB::statement('ALTER TABLE stores ALTER COLUMN geocode_source SET NOT NULL');
    }
};
