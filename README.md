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
php artisan subandl:install              # publishes config/subandl.php, runs the migration (Blade UI)
php artisan subandl:install --ui=vue     # or --ui=react
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
| Routes | `/subscription`, `/subscription/{license,update,backup}`, `/license/*` JSON endpoints, `/subandl/widget` + `/subandl/assets/*` (widget), `/terms`, `/clear-cache`. Everything except `status` and `health-report` requires `auth`. |
| Middleware | `EnsureLicenseValid` (redirects when the license is unusable), `RunScheduledTasks` (a web-triggered cron for hosts without one) and `InjectWidget` (adds the [global widget](#global-widget) to every HTML page). All three are appended to the `web` group and are also available as the `subandl.license`, `subandl.scheduler` and `subandl.widget` aliases. |
| Scheduler | `subandl:license-check` every 30 minutes, `subandl:update-check` hourly (announces a new version; installs it only with `update_auto_apply`), `subandl:backup` every 15 minutes. Each one self-throttles. |
| Commands | `subandl:install`, `subandl:status`, `subandl:license-check [--force]`, `subandl:update-check [--force]`, `subandl:backup [--force]` |
| Events | `SUBandL\Events\LicenseVerified`, `UpdateFinished`, `BackupFinished` |

Add the system cron (`* * * * * php artisan schedule:run`) when you can. Without it, the web scheduler still triggers the checks from logged-in traffic.

---

## Global widget

`InjectWidget` adds a small edge tab to every page for signed-in users. You don't need to change any layout. It works the same in Blade, Vue and React apps, and it follows Inertia page visits. The tab opens a full-screen panel with two tabs:

- **License.** The payment reminder, shown only while `due_amount > 0` and with no countdown. While an amount is due the panel opens on its own, and "Remind me later" (or closing it) hides it for `widget.reminder_minutes`. Below it are the license status and the license key form (Save & verify, Refresh).
- **Update & Backup.** The installed version, update status and "Check for update"; the last successful backup, the automatic-backup toggle and "Backup now"; and the update and backup history.

With the Blade UI, `/subscription/license` and `/subscription/update` render only a placeholder, and the widget shows the same panel there as the page itself ("page mode": no close button, a Back link instead). The subscription UI therefore exists only once. With `widget.enabled` off, those pages fall back to the standalone Blade markup.

When the provider reports a newer version, a **modal** offers "Update now", which runs the same step-by-step updater as the subscription page. "Later" snoozes that version for `widget.update_snooze_hours`. If the provider sets `force_update`, the modal has no "Later" button.

The License tab follows the `license` access ability (the payment reminder is shown to everyone), and the update and backup sections follow the `update` and `backup` abilities. Everything is configured under `widget` in `config/subandl.php`: `enabled`, `hidden_on`, `reminder_minutes`, `update_snooze_hours` and `payment_greeting`. To open the panel from your own UI, add `data-subandl-open` (optionally `="license"` or `="update"`) to any element or call `window.SUBandLWidget.open(tab)`. On a link, keep the `href` to `/subscription/...` as the fallback: the widget cancels the navigation only when it is loaded.

The widget also sends a tab that was left open to the subscription page once the stored license becomes unusable, so the host application needs no polling of its own.

### Page loads never contact the provider

Every page, the widget's `/subandl/widget` snapshot and the middlewares read only the stored state. License, update and backup checks run as detached background processes (`RunScheduledTasks` or the scheduler), each throttled on its own. A provider that is slow or down therefore never delays a page, and a failed check never overwrites the last good license state. The only calls from the browser to the provider are the reachability ping, which one browser makes at most once every 30 minutes, and actions a user starts explicitly (Save License, Refresh, Check for update, Update now, Backup now).

### Daily backup guarantee

With `backup_daily_minimum` on (the default), a backup runs whenever there has been no **successful** backup in the last 24 hours, even when the customer's automatic backup toggle is off. After a failure it retries every `backup_daily_retry_minutes`. It needs a usable license, and it runs from the scheduler, the web scheduler and the widget, so it works without a system cron.

### Updates: notify, then apply

With `update_auto_apply` set to `false` (the default), `subandl:update-check` only records the newest version and the widget's modal asks the customer to apply it. Set it to `true` to install new versions unattended, which is how v1.1 behaved.

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

- **UI**: see [User interface](#user-interface-blade-vue-react) below.
- **Permissions**: set `access.resolver` to any class implementing `SUBandL\Contracts\AccessResolver`. The default allows any logged-in user. If you define a Gate named after an ability (`license`, `licenseUpdate`, `licenseBackup`), that Gate decides instead.
- **Tables**: `tables.*` lets you rename the tables.
- **Updates**: `update.protected_paths`, `update.blocked_commands`, `update.after_update_commands` and `update.remove_manifest`.
- **Backup**: the connection, dump binaries and options, and the chunk size.

> `.env` is never shipped in a release zip. `config/subandl.php` is shipped. Put anything that must reach existing customers in the config file.

---

## User interface: Blade, Vue, React

The package ships three UIs. All of them use one shared core:

```
resources/js/subandl/subandl.js    API calls, update/backup step flows, formatting, terms text
resources/js/subandl/subandl.css   styles, all scoped under .subandl
resources/views/*.blade.php        Blade UI: inlines the core, needs no build step
resources/js/Pages/SUBandL/*.vue   Vue 3 Inertia pages   ┐ presentation only;
resources/js/Pages/SUBandL/*.jsx   React Inertia pages   ┘ they import ../../subandl/
```

Fix a behaviour once in `subandl.js`, or a style once in `subandl.css`, and all three UIs pick it up. The page files only render.

| Driver | Setup | Requires |
|---|---|---|
| `blade` (default) | Nothing to do. | Nothing |
| `vue` | `php artisan subandl:install --ui=vue`, then `npm run build` | Inertia + Vue 3 |
| `react` | `php artisan subandl:install --ui=react`, then `npm run build` | Inertia + React |
| `inertia` | Set your own page names in `ui.pages`. | Inertia |

`--ui=vue` or `--ui=react` publishes the pages to `resources/js/Pages/SUBandL/` and the core to `resources/js/subandl/`, then sets `ui.driver`. Inertia resolves the pages as `SUBandL/Subscription`, `SUBandL/VerificationRequired` and `SUBandL/Terms`. After publishing, you can wrap them in your app layout, for example with `defineOptions({ layout })` in Vue or `Page.layout = …` in React. Re-run the publish with `--force` to pick up package updates. If you do not have Inertia installed, the driver falls back to Blade.

Every page receives the same props: `currentVersion`, `tab`, `canUpdate`, `canBackup`, `licenseKey`, `backupEnabled`, `backupIntervalHours`, `base` and `urls {license, update, terms}`. The verification page gets `status` and `message` instead. Custom `inertia` pages receive these props too.

To restyle the Blade pages: `php artisan vendor:publish --tag=subandl-views`.

## Release package format (for the portal)

The update zip holds paths relative to the project root, for example `app/...` and `vendor/...`. Optional extras:

- `checksum` / `sha256` in the `/api/check-update` response. The updater rejects the download if its SHA-256 does not match.
- `commands: ["my:command --opt"]` in the response. These run after migrations. Destructive commands (`migrate:fresh`, `db:wipe`, …) are always refused.
- `subandl-remove.txt` at the zip root. It lists one relative path (file or folder) per line to delete after extraction, and `#` starts a comment. Protected paths and `..` are ignored. The file deletes itself afterwards.

## Provider API contract

`POST /api/verify-license`, `/api/check-update`, `/api/{module}-status`, `/api/backup/{initialize,chunk,complete}`. Every call carries `software`, `license_key`, `device_id` and `domain`.
