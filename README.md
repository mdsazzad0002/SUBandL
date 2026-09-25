# SUBandL — Software Update, Backup and License

A drop-in Laravel package (Laravel 10–13, PHP 8.1+) that adds the following to any application:

- **License / subscription**: verifies the license with the provider portal and binds it to the device. Access is gated when the license is invalid or expired, with a grace period, and optional modules can be enabled per license.
- **Updater**: one-click, step-by-step version updates. It takes a backup before updating, verifies the checksum, blocks zip-slip paths, protects files that must not be overwritten, runs migrations and removes files that a release lists for deletion.
- **Backup**: chunked, resumable database uploads to the provider. Supports MySQL/MariaDB, PostgreSQL and SQLite.

The folder is self-contained. Put it anywhere (`packages/SUBandL`, `modules/subandl`, `../shared/SUBandL`, …) and point Composer at that folder.

---

## Install in a project

### 1. Tell Composer where the package is

**From GitHub (recommended for new projects).** In the project's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/mdsazzad0002/SUBandL" }
],
```

```bash
composer require subandl/subandl:^1.0
```

**Option A: Composer path repository.** Use this for a normal package install. In the project's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "packages/SUBandL", "options": { "symlink": false } }
],
```

```bash
composer require subandl/subandl:@dev
```

`symlink: false` copies the package into `vendor/`, so a release zip that ships `vendor/` contains real files and not a symlink. Run `composer update subandl/subandl` after you edit the package.

**Option B: direct autoload.** Use this when the package lives inside the project's repository, which is how this POS project does it. In `composer.json`:

```json
"autoload": { "psr-4": { "SUBandL\\": "packages/SUBandL/src/" } }
```

Then register the provider in `config/app.php` under `providers` (or in `bootstrap/providers.php` on Laravel 11+):

```php
SUBandL\SUBandLServiceProvider::class,
```

Run `composer dump-autoload`.

> Register the provider explicitly even with Option A when `bootstrap/cache/packages.php` is committed or shipped. If a customer's cached manifest was built before SUBandL existed, package discovery will not load it. Registering twice is harmless.

The package also works from a private Git repository (`"type": "vcs"`) or from a zip (`"type": "artifact"`).

### 2. Configure and migrate

```bash
php artisan subandl:install      # publishes config/subandl.php and runs the migration
```

Add to `.env`:

```dotenv
SUBANDL_SOFTWARE_SLUG=my-product        # the product slug on the portal
SUBANDL_PROVIDER_URL=https://portal.likesoftbd.com
APP_VERSION=1.0.0
# SUBANDL_LICENSE_KEY=...               # optional; only seeds the first install
```

The older `LICENSE_PROVIDER_URL`, `LICENSE_SOFTWARE_SLUG` and `LICENSE_KEY` variables still work as fallbacks.

The migration is **idempotent**. On a fresh database it creates the tables. If the tables already exist (a project that had the license system before it became a package), it only adds the missing columns. Existing license rows, device IDs and history are kept.

### 3. Done

Open `/subscription`. Middleware, routes, the scheduler and the UI all register themselves. You do not need to edit the Kernel or `bootstrap/app.php`.

---

## What gets registered

| Piece | Details |
|---|---|
| Routes | `/subscription`, `/subscription/{license,update,backup}`, `/license/*` JSON endpoints, `/terms`, `/clear-cache`. Everything except `status` and `health-report` requires `auth`. |
| Middleware | `EnsureLicenseValid` (redirects when the license is unusable) and `RunScheduledTasks` (a web-triggered cron for hosts without one). Both are appended to the `web` group and are also available as the `subandl.license` and `subandl.scheduler` aliases. |
| Scheduler | `subandl:license-check` every 30 minutes, `subandl:update-check` hourly, `subandl:backup` every 15 minutes. Each one self-throttles. |
| Commands | `subandl:install`, `subandl:status`, `subandl:license-check [--force]`, `subandl:update-check [--force]`, `subandl:backup [--force]` |
| Events | `SUBandL\Events\LicenseVerified`, `UpdateFinished`, `BackupFinished` |

Add the system cron (`* * * * * php artisan schedule:run`) when you can. Without it, the web scheduler still triggers the checks from logged-in traffic.

---

## Using it in code

```php
use SUBandL\Facades\SUBandL;

SUBandL::isValid();                   // cached, no HTTP call
SUBandL::state()->expires_at;         // the full license row
SUBandL::verify(force: true);         // live check
SUBandL::module('careflow');          // ['ok' => true, 'enabled' => true, 'message' => null] via /api/careflow-status
SUBandL::checkUpdate();               // notify only
SUBandL::update();                    // applies the next single version step
SUBandL::backup(force: true);
SUBandL::client()->verify()['max_branches'];
```

Protect a single route group only, instead of the whole `web` group:

```php
// config: 'middleware' => ['auto_register' => false]
Route::middleware(['web', 'auth', 'subandl.license'])->group(...);
```

---

## Customising

Everything lives in `config/subandl.php`:

- **UI**: `ui.driver = blade` uses the built-in standalone pages, which work in any project. `inertia` renders your own components listed in `ui.pages`. To restyle the Blade pages, run `vendor:publish --tag=subandl-views`.
- **Permissions**: set `access.resolver` to any class implementing `SUBandL\Contracts\AccessResolver`. The default allows any logged-in user. If you define a Gate named after an ability (`license`, `licenseUpdate`, `licenseBackup`), that Gate decides instead.
- **Tables**: `tables.*` lets you rename the tables.
- **Updates**: `update.protected_paths`, `update.blocked_commands`, `update.after_update_commands` and `update.remove_manifest`.
- **Backup**: the connection, dump binaries and options, and the chunk size.

> `.env` is never shipped in a release zip. `config/subandl.php` is shipped. Put anything that must reach existing customers in the config file.

---

## Release package format (for the portal)

The update zip holds paths relative to the project root, for example `app/...` and `vendor/...`. Optional extras:

- `checksum` / `sha256` in the `/api/check-update` response. The updater rejects the download if its SHA-256 does not match.
- `commands: ["my:command --opt"]` in the response. These run after migrations. Destructive commands (`migrate:fresh`, `db:wipe`, …) are always refused.
- `subandl-remove.txt` at the zip root. It lists one relative path (file or folder) per line to delete after extraction, and `#` starts a comment. Protected paths and `..` are ignored. The file deletes itself afterwards.

## Provider API contract

`POST /api/verify-license`, `/api/check-update`, `/api/{module}-status`, `/api/backup/{initialize,chunk,complete}`. Every call carries `software`, `license_key`, `device_id` and `domain`.
