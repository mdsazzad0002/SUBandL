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

Add the system cron (`* * * * * php artisan schedule:run`) when you can. Without it, the web scheduler still triggers the checks from page visits.

---

## Global widget

`InjectWidget` adds a small edge tab to every page for signed-in users. You don't need to change any layout. It works the same in Blade, Vue and React apps, and it follows Inertia page visits. The tab opens an offcanvas panel (1000px wide, at most 75% of the screen, full width on phones) with two tabs:

- **License.** The payment details (amount due, open invoices, paid-through date, how to pay), then the license status and the license key form (Save & verify, Refresh).
- **Update & Backup.** The installed version, update status and "Check for update"; the last successful backup, the automatic-backup toggle and "Backup now"; and the update and backup history.

With the Blade UI, `/subscription/license` and `/subscription/update` render only a placeholder, and the widget shows the same panel there full screen as the page itself ("page mode": no close button, a Back link instead). The subscription UI therefore exists only once. With `widget.enabled` off, those pages fall back to the standalone Blade markup.

**Payment reminder.** While money is owed, a payment modal pops up on its own. Once the payment is late (the grace period is running) it comes back every `widget.reminder_minutes` (10), and a banner at the bottom of the screen stays until the payment arrives, so "Remind me later" hides only the popup. Before the due date the popup comes back every `widget.due_reminder_hours` (24) instead. While a payment is owed the license is re-verified every `verify_cache_minutes_when_due` (30), so a payment unlocks the app within minutes.

When the provider reports a newer version, a **modal** offers "Update now", which runs the same step-by-step updater as the subscription page: check the provider server, back up and install, then reload. "Later" snoozes that version for `widget.update_snooze_hours`. If the provider sets `force_update`, the modal has no "Later" button.

The License tab follows the `license` access ability (the payment reminder is shown to everyone), and the update and backup sections follow the `update` and `backup` abilities. Everything is configured under `widget` in `config/subandl.php`: `enabled`, `edge_tab` (the floating edge button; turn it off when your UI opens the panel with `data-subandl-open`), `hidden_on`, `reminder_minutes`, `update_snooze_hours` and `payment_greeting`. To open the panel from your own UI, add `data-subandl-open` (optionally `="license"` or `="update"`) to any element or call `window.SUBandLWidget.open(tab)`. On a link, keep the `href` to `/subscription/...` as the fallback: the widget cancels the navigation only when it is loaded.

The widget also sends a tab that was left open to the subscription page once the stored license becomes unusable, so the host application needs no polling of its own.

### Page loads never contact the provider

Every page, the widget's `/subandl/widget` snapshot and the middlewares read only the stored state. License, update and backup checks run as detached background processes (`RunScheduledTasks` or the scheduler), each throttled on its own. A provider that is slow or down therefore never delays a page, and a failed check never overwrites the last good license state. The only calls from the browser to the provider are the reachability ping, which one browser makes at most once every 30 minutes, and actions a user starts explicitly (Save License, Refresh, Check for update, Update now, Backup now).

### Plans: monthly and lifetime

The provider decides, and SUBandL shows it:

- **Monthly.** The license runs to its paid-through date. Every paid monthly fee moves that date one month on. After it passes (or after an unpaid fee's due date), the app keeps working for the grace days with a reminder, then pauses until the fee is paid. Updates are always included.
- **Lifetime.** The license never expires. New versions come only with the monthly **update subscription**. Without it the update modal still announces a new version, marked as locked with its monthly price, but the version can't be installed.

### Health check before every live call

Every live call is preceded by a server-side ping to the provider (`/api/ping`): license checks, update checks (the source of the update popup), backups and updates. While the provider is unhealthy, no live call is made at all. The ping and the live call are each tried at least twice (`health_attempts`, `live_attempts`). If they still fail, automatic work waits `health_retry_minutes` (at least 30) and then tries again by itself. An explicit click (Check for update, Backup now, Update now) probes again right away.

Every step is written to the **activity log** (`license_activity_logs`), which appears in the History table on the Update & Backup page next to backups and updates. For example: "Provider unreachable after 2 attempts (Connection timed out); update check skipped. Next try after 21:48", or "/check-update answered on attempt 2". The Update card also shows whether the provider server is reachable and when the next try is.

### Instant check on a device or domain change

The known device (machine + install folder) and domain are stored in the database (`license_installations.fingerprint` / `domain`). When a request comes from a different device or domain, the license is re-verified live right then (`verify_on_identity_change`). A copied install is caught on its first page load, and a legitimate move is confirmed just as fast. A first visit, a fresh install or an upgrade only records the baseline, without contacting the provider. The live call gives up after `identity_check_timeout` seconds (5); while the provider is down the page carries on with the stored state, and the check is retried after the health back-off. Background checks send the stored domain, so the provider's domain binding applies to them too. IP addresses and `localhost` never count as a domain.

### Daily backup guarantee

Automatic backups (customer toggle on) run every `backup_interval_hours` (8, never less than `backup_min_interval_hours`). With `backup_daily_minimum` on (the default), a backup also runs whenever there has been no **successful** backup in the last 24 hours, even when the customer's automatic backup toggle is off. After a failure it retries every `backup_daily_retry_minutes`. It needs a usable license, and it runs from the scheduler, the web scheduler and the widget, so it works without a system cron.

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
