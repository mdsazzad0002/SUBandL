<?php

/*
|--------------------------------------------------------------------------
| SUBandL — Software Update, Backup and License
|--------------------------------------------------------------------------
|
| Every value here has a working default, so a new project only needs
| SUBANDL_SOFTWARE_SLUG (and optionally SUBANDL_LICENSE_KEY) in .env.
|
| Publish this file with `php artisan subandl:install` when a project needs
| project-specific values. Remember that a published config/subandl.php
| ships inside release packages, while .env never does — anything that
| must reach already-installed customers belongs here, not in .env.
|
*/

return [

    // Base URL of the vendor's license/update/backup server.
    'provider_url' => env('SUBANDL_PROVIDER_URL', env('LICENSE_PROVIDER_URL', 'https://portal.likesoftbd.com')),

    // Slug identifying this software product to the provider.
    'software_slug' => env('SUBANDL_SOFTWARE_SLUG', env('LICENSE_SOFTWARE_SLUG', 'pos-software')),

    // Only used ONCE, to seed the license row on a brand-new install. After
    // that the database row is the single source of truth (see LicenseState).
    'license_key' => env('SUBANDL_LICENSE_KEY', env('LICENSE_KEY')),

    // The installed version sent to the update server, and the .env key the
    // updater rewrites after a successful update.
    'version' => env('APP_VERSION', '1.0.0'),
    'version_env_key' => 'APP_VERSION',

    'http_timeout' => 15,

    // How long a cached verification is trusted before an automatic
    // (non-forced) refresh attempts another live check. 720 = 2 checks/day.
    // Explicit user actions (Save License, Refresh) always check live.
    'verify_cache_minutes' => (int) env('SUBANDL_VERIFY_CACHE_MINUTES', env('LICENSE_VERIFY_CACHE_MINUTES', 720)),

    // Minimum gap between two automatic update checks.
    'update_check_hours' => 2,

    // A backup is due this many hours after the previous attempt.
    'backup_interval_hours' => 6,

    // A running backup/update flag older than this is treated as a dead run.
    'stale_minutes' => 30,

    // Guarantee at least one successful backup every 24 hours, even when the
    // customer's automatic backup toggle is off. While overdue, a failed
    // attempt is retried every backup_daily_retry_minutes.
    'backup_daily_minimum' => true,
    'backup_daily_retry_minutes' => 60,

    // false: the scheduler only CHECKS for a new version and the widget's
    // modal lets the customer apply it. true: install it unattended.
    'update_auto_apply' => false,

    /*
    |--------------------------------------------------------------------------
    | Global widget
    |--------------------------------------------------------------------------
    | Injected into every HTML page of the web group (no host code needed): an
    | edge tab that opens an offcanvas panel with the payment reminder (only
    | while an amount is due), the version/update status and the backup
    | status, plus a modal whenever a newer version is available.
    */
    'widget' => [
        'enabled' => true,

        // The floating tab on the screen edge that opens the panel. Turn it off
        // when the app opens the panel itself (data-subandl-open links); the
        // panel still opens on its own for a payment due or a new version.
        'edge_tab' => true,

        // Request::is patterns where the widget stays hidden.
        'hidden_on' => ['login', 'register', 'password/*', 'subscription', 'subscription/*', 'license/*', 'terms'],

        // "Remind me later" on the payment reminder hides it for this long.
        'reminder_minutes' => 10,

        // "Later" on the update modal hides it for this long (per version).
        'update_snooze_hours' => 6,

        // Optional lines shown above the amount due, e.g. a greeting.
        'payment_greeting' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database tables
    |--------------------------------------------------------------------------
    */
    'tables' => [
        'states' => 'license_states',
        'installations' => 'license_installations',
        'backup_histories' => 'backup_histories',
        'update_histories' => 'update_histories',
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | The endpoint paths (/license/*, /subscription/*) are fixed so any
    | frontend written against them keeps working; `prefix` moves them all.
    */
    'routes' => [
        'enabled' => true,
        'prefix' => '',
        'middleware' => ['web'],
        // Adds POST /clear-cache (auth only): clears caches and re-verifies.
        'clear_cache' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | With auto_register on, both middlewares are appended to `group`, so no
    | Kernel / bootstrap/app.php edit is needed. They are also available as
    | the aliases `subandl.license` and `subandl.scheduler`.
    */
    'middleware' => [
        'auto_register' => true,
        'group' => 'web',
        // Redirect authenticated users to the subscription page when the
        // license is unusable.
        'enforce_license' => true,
        // Poor-man's cron: trigger license/update/backup checks from web
        // traffic for hosts without a system cron.
        'web_scheduler' => true,
    ],

    // Paths (Request::is patterns) that stay reachable with an invalid license.
    'allowed_when_invalid' => [
        'license/*',
        'subscription',
        'subscription/*',
        'terms',
        'logout',
        'login',
        '/',
    ],

    // Where a user with a valid license is sent from the verification page.
    // A route name; falls back to '/' when the route does not exist.
    'home_route' => 'dashboard',

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    | `resolver` decides who may open the license / update / backup pages. It
    | must implement SUBandL\Contracts\AccessResolver. The default allows any
    | authenticated user, unless a Gate with the ability name is defined — then
    | the Gate decides.
    */
    'access' => [
        'resolver' => SUBandL\Support\DefaultAccessResolver::class,
        'abilities' => [
            'license' => 'license',
            'update' => 'licenseUpdate',
            'backup' => 'licenseBackup',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User interface
    |--------------------------------------------------------------------------
    | `blade`   — the package's own standalone pages; no build step (default).
    | `vue`     — bundled Vue 3 Inertia pages (SUBandL/*), published with
    |             `php artisan subandl:install --ui=vue`.
    | `react`   — bundled React Inertia pages (SUBandL/*), published with
    |             `php artisan subandl:install --ui=react`.
    | `inertia` — your own Inertia components, named in `pages` below.
    |
    | All three bundled UIs share resources/js/subandl/{subandl.js,subandl.css}
    | for API calls, the update/backup flows and styling.
    */
    'ui' => [
        'driver' => 'blade',
        'pages' => [
            'verification_required' => 'License/VerificationRequired',
            'terms' => 'License/Terms',
            'license' => 'Subscription/License',
            'update' => 'Subscription/Update',
            'forbidden' => 'Error/Forbidden',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    | Registers subandl:license-check, subandl:update-check and subandl:backup
    | on Laravel's scheduler. Each command self-throttles, so these are just
    | "often enough to notice when due".
    */
    'schedule' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup
    |--------------------------------------------------------------------------
    */
    'backup' => [
        // null = the default database connection.
        'connection' => null,
        'staging_path' => storage_path('app/backup-staging'),
        'chunk_size' => 2 * 1024 * 1024,
        'chunk_retry_attempts' => 5,
        'mysqldump_binary' => 'mysqldump',
        'mysqldump_options' => ['--single-transaction', '--quick'],
        'pg_dump_binary' => 'pg_dump',
    ],

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */
    'update' => [
        'staging_path' => storage_path('app/update-staging'),
        'download_timeout' => 300,
        'run_migrations' => true,

        // Paths (relative to base_path) a release package may never overwrite.
        'protected_paths' => [
            '.env',
            '.git/',
            'storage/',
            'database/database.sqlite',
            'node_modules/',
        ],

        // Artisan commands the update server may never run on a live install.
        'blocked_commands' => [
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'migrate:rollback',
            'db:wipe',
            'db:seed',
        ],

        // A file at the root of a release zip listing (one per line) files or
        // folders to delete after extraction — lets a release remove code
        // that an extract-only update would otherwise leave behind.
        'remove_manifest' => 'subandl-remove.txt',

        // Run after every successful update.
        'after_update_commands' => ['config:clear', 'cache:clear', 'view:clear'],
    ],

];
