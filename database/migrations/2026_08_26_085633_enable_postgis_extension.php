<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables PostGIS.
 *
 * Done as a migration rather than as a manual setup step so a fresh clone gets it
 * without anyone having to remember. Distance calculation and radius filtering on the
 * seller list run in the database, and none of that works without this extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        /*
         * Deliberately not dropped. Any geography column in the schema depends on it,
         * and dropping the extension would cascade into losing them. Removing PostGIS
         * is a decision for a human at a psql prompt, not a side effect of a rollback.
         */
    }
};
