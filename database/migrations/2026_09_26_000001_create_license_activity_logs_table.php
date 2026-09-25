<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log shown on the Update & Backup page (health checks, live
 * checks, retries, skips). Create-only and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('subandl.tables.activity_logs', 'license_activity_logs');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('type', 32)->index();
            $t->boolean('ok')->default(false);
            $t->text('message')->nullable();
            $t->json('context')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally empty, like the base migration.
    }
};
