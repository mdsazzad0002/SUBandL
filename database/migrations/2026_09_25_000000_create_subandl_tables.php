<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent: creates each SUBandL table on a fresh install, and on an
 * install that already has them (e.g. a project that shipped the license
 * system before it became a package) only adds whichever columns are
 * missing. Safe to run on any existing database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensure(config('subandl.tables.installations', 'license_installations'), [
            'installation_uuid' => fn (Blueprint $t) => $t->uuid('installation_uuid')->unique(),
            'signing_secret' => fn (Blueprint $t) => $t->string('signing_secret'),
            'domain' => fn (Blueprint $t) => $t->string('domain')->nullable(),
            'fingerprint' => fn (Blueprint $t) => $t->string('fingerprint', 64)->nullable(),
        ]);

        $this->ensure(config('subandl.tables.states', 'license_states'), [
            'license_key' => fn (Blueprint $t) => $t->string('license_key')->nullable(),
            'domain' => fn (Blueprint $t) => $t->string('domain')->nullable(),
            'client_name' => fn (Blueprint $t) => $t->string('client_name')->nullable(),
            'client_email' => fn (Blueprint $t) => $t->string('client_email')->nullable(),
            'subscription_type' => fn (Blueprint $t) => $t->string('subscription_type', 32)->nullable(),
            'status' => fn (Blueprint $t) => $t->string('status', 32)->default('unverified'),
            'expires_at' => fn (Blueprint $t) => $t->timestamp('expires_at')->nullable(),
            'update_support_expires_at' => fn (Blueprint $t) => $t->timestamp('update_support_expires_at')->nullable(),
            'grace_days' => fn (Blueprint $t) => $t->unsignedInteger('grace_days')->nullable(),
            'in_grace_period' => fn (Blueprint $t) => $t->boolean('in_grace_period')->default(false),
            'grace_ends_at' => fn (Blueprint $t) => $t->date('grace_ends_at')->nullable(),
            'due_amount' => fn (Blueprint $t) => $t->decimal('due_amount', 12, 2)->nullable(),
            'payment_info' => fn (Blueprint $t) => $t->json('payment_info')->nullable(),
            'monthly_fee' => fn (Blueprint $t) => $t->decimal('monthly_fee', 12, 2)->nullable(),
            'backup_enabled' => fn (Blueprint $t) => $t->boolean('backup_enabled')->default(false),
            'backup_running' => fn (Blueprint $t) => $t->boolean('backup_running')->default(false),
            'backup_started_at' => fn (Blueprint $t) => $t->timestamp('backup_started_at')->nullable(),
            'code_integrity_ok' => fn (Blueprint $t) => $t->boolean('code_integrity_ok')->default(true),
            'last_verified_at' => fn (Blueprint $t) => $t->timestamp('last_verified_at')->nullable(),
            'last_verification_response' => fn (Blueprint $t) => $t->json('last_verification_response')->nullable(),
            'last_verification_error' => fn (Blueprint $t) => $t->text('last_verification_error')->nullable(),
            'last_update_check_at' => fn (Blueprint $t) => $t->timestamp('last_update_check_at')->nullable(),
            'last_backup_at' => fn (Blueprint $t) => $t->timestamp('last_backup_at')->nullable(),
            'last_backup_status' => fn (Blueprint $t) => $t->string('last_backup_status')->nullable(),
            'last_backup_message' => fn (Blueprint $t) => $t->text('last_backup_message')->nullable(),
            'update_running' => fn (Blueprint $t) => $t->boolean('update_running')->default(false),
            'update_started_at' => fn (Blueprint $t) => $t->timestamp('update_started_at')->nullable(),
            'last_update_status' => fn (Blueprint $t) => $t->string('last_update_status')->nullable(),
            'last_update_message' => fn (Blueprint $t) => $t->text('last_update_message')->nullable(),
        ]);

        $this->ensure(config('subandl.tables.backup_histories', 'backup_histories'), [
            'ok' => fn (Blueprint $t) => $t->boolean('ok')->default(false),
            'backup_id' => fn (Blueprint $t) => $t->string('backup_id')->nullable(),
            'message' => fn (Blueprint $t) => $t->text('message')->nullable(),
        ]);

        $this->ensure(config('subandl.tables.update_histories', 'update_histories'), [
            'ok' => fn (Blueprint $t) => $t->boolean('ok')->default(false),
            'from_version' => fn (Blueprint $t) => $t->string('from_version')->nullable(),
            'to_version' => fn (Blueprint $t) => $t->string('to_version')->nullable(),
            'message' => fn (Blueprint $t) => $t->text('message')->nullable(),
        ]);
    }

    public function down(): void
    {
        // Intentionally empty: rolling back must never drop a customer's
        // license identity (its device binding lives in these rows).
    }

    /**
     * @param  array<string, \Closure(Blueprint): mixed>  $columns
     */
    private function ensure(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $define) {
                    $define($t);
                }
                $t->timestamps();
            });

            return;
        }

        $missing = array_filter(
            $columns,
            fn ($column) => ! Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );

        if ($missing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($missing) {
            foreach ($missing as $define) {
                $define($t);
            }
        });
    }
};
