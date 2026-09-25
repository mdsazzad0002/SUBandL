<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the device fingerprint (machine + install folder) the license was
 * last verified on, next to the domain, so a move to another device or
 * domain is detected against the database (see IdentityWatch). Add-only and
 * idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('subandl.tables.installations', 'license_installations');

        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'fingerprint')) {
            Schema::table($table, fn (Blueprint $t) => $t->string('fingerprint', 64)->nullable());
        }
    }

    public function down(): void
    {
        // Intentionally empty, like the base migration.
    }
};
