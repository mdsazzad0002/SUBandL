<?php

namespace SUBandL\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use SUBandL\Backup\BackupService;
use SUBandL\License\LicenseClient;
use SUBandL\License\LicenseVerifier;
use SUBandL\License\ServerHealth;
use SUBandL\Models\ActivityLog;
use SUBandL\Models\BackupHistory;
use SUBandL\Models\LicenseState;
use SUBandL\Models\UpdateHistory;
use SUBandL\SUBandL;
use SUBandL\Support\Access;
use SUBandL\Support\BackgroundArtisan;
use SUBandL\Support\UpdateNotice;
use SUBandL\Update\UpdateApplier;

class SubscriptionController extends Controller
{
    /* ------------------------------------------------------------------ pages */

    public function verificationRequired()
    {
        $state = LicenseState::current();

        if ($state->isUsable()) {
            return redirect(self::homeUrl());
        }

        // Signed-in users get the Update & Backup page (or the License tab)
        // instead; this plain page is only the fallback.
        if (auth()->check() && ($route = $state->redirectRouteName()) !== 'license.verification-required') {
            return redirect()->route($route);
        }

        return $this->render('verification_required', 'subandl::verification-required', [
            'status' => $state->status,
            'message' => $state->last_verification_error,
        ]);
    }

    public function terms()
    {
        return $this->render('terms', 'subandl::terms');
    }

    public function subscriptionLicense()
    {
        if (! Access::allows('license')) {
            return $this->forbidden();
        }

        return $this->render('license', 'subandl::subscription', $this->subscriptionProps(
            'license',
            Access::allows('update'),
            Access::allows('backup'),
        ));
    }

    public function subscriptionUpdate()
    {
        $canUpdate = Access::allows('update');
        $canBackup = Access::allows('backup');

        if (! $canUpdate && ! $canBackup) {
            return $this->forbidden();
        }

        return $this->render('update', 'subandl::subscription', $this->subscriptionProps(
            $canUpdate ? 'update' : 'backup',
            $canUpdate,
            $canBackup,
        ));
    }

    public function subscriptionBackup()
    {
        if (! Access::allows('backup')) {
            return $this->forbidden();
        }

        return redirect()->route('subscription.update');
    }

    /* ---------------------------------------------------------------- license */

    public function status()
    {
        return response()->json($this->formatStatus(LicenseState::current()));
    }

    public function saveLicense(Request $request, LicenseVerifier $verifier)
    {
        if ($denied = $this->denyUnless('license')) {
            return $denied;
        }

        $request->validate(['license' => ['required', 'string', 'max:255']]);

        $key = trim($request->input('license'));
        $state = LicenseState::current();

        if ($state->license_key !== $key) {
            $state->domain = null;
        }

        $state->license_key = $key;
        $state->save();

        $state = $verifier->refresh(force: true);

        if (! $state->isUsable()) {
            return response()->json([
                'status' => false,
                'message' => $state->last_verification_error ?? 'License could not be verified.',
            ], 422);
        }

        return response()->json(['status' => true, 'message' => 'License saved and verified.']);
    }

    /**
     * The explicit Refresh button always checks live; a periodic background ping
     * passes `automatic: true` so it respects the verifier's self-throttle.
     */
    public function checkLicense(Request $request, LicenseVerifier $verifier)
    {
        $verifier->refresh(force: ! $request->boolean('automatic'));

        return $this->status();
    }

    /**
     * The browser checks provider reachability and reports it here, so server-side
     * tasks read a cached result instead of each pinging the provider (see ServerHealth).
     */
    public function reportHealth(Request $request, ServerHealth $health)
    {
        $request->validate(['healthy' => 'required|boolean']);

        $health->record($request->boolean('healthy'));

        return response()->json(['status' => 'ok']);
    }

    /* ----------------------------------------------------------------- backup */

    public function toggleBackup(Request $request)
    {
        if ($denied = $this->denyUnless('backup') ?? $this->denyWithoutLicense()) {
            return $denied;
        }

        $request->validate(['enabled' => ['required', 'boolean']]);

        $state = LicenseState::current();
        $state->backup_enabled = $request->boolean('enabled');
        $state->save();

        return response()->json(['status' => true, 'backup_enabled' => $state->backup_enabled]);
    }

    /**
     * Starts the backup as a detached process and returns immediately; the frontend
     * polls backupStatus(). A detached OS process isn't bound by this request's
     * max_execution_time or a proxy timeout the way an afterResponse() job is.
     */
    public function runBackup(BackupService $service)
    {
        if ($denied = $this->denyUnless('backup') ?? $this->denyWithoutLicense()) {
            return $denied;
        }

        if ($service->isRunning()) {
            return response()->json(['ok' => false, 'running' => true, 'message' => 'A backup is already running.'], 409);
        }

        if (BackgroundArtisan::dispatch('subandl:backup', ['--force'])) {
            return response()->json(['ok' => true, 'running' => true, 'message' => 'Backup started.']);
        }

        // Couldn't spawn a process (exec disabled) — run inline so the button still works.
        set_time_limit(0);
        $result = $service->runBackup(force: true);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Stand-in for a system cron on hosts without one: the browser calls this
     * periodically; BackupService's own due-time/enabled checks decide whether
     * anything actually happens.
     */
    public function autoBackupCheck(BackupService $service)
    {
        if ($service->isRunning()) {
            return response()->json(['ok' => true, 'running' => true]);
        }

        if (BackgroundArtisan::dispatch('subandl:backup')) {
            return response()->json(['ok' => true, 'dispatched' => true]);
        }

        set_time_limit(0);

        return response()->json($service->runBackup(force: false));
    }

    public function backupStatus(BackupService $service)
    {
        $state = LicenseState::current();

        return response()->json([
            'running' => $service->isRunning($state),
            'last_backup_at' => optional($state->last_backup_at)->toDateTimeString(),
            'last_backup_status' => $state->last_backup_status,
            'last_backup_message' => $state->last_backup_message,
        ]);
    }

    public function backupHistory()
    {
        $history = BackupHistory::query()
            ->latest('id')
            ->limit(20)
            ->get(['id', 'ok', 'backup_id', 'message', 'created_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'ok' => $row->ok,
                'backup_id' => $row->backup_id,
                'message' => $row->message,
                'created_at' => $row->created_at?->toDateTimeString(),
            ]);

        return response()->json(['history' => $history]);
    }

    /* ----------------------------------------------------------------- update */

    /**
     * Notify-only (never applies anything). `automatic: true` respects the same
     * reachability check and interval cap as the background command.
     */
    public function checkUpdate(Request $request, LicenseClient $client, ServerHealth $health)
    {
        if ($denied = $this->denyWithoutLicense()) {
            return $denied;
        }

        $state = LicenseState::current();

        if ($request->boolean('automatic')) {
            $hours = (int) config('subandl.update_check_hours', 2);
            if ($state->last_update_check_at && $state->last_update_check_at->gt(now()->subHours($hours))) {
                // Not due for a live check — answer from the last one instead of
                // reporting "no update" and hiding a pending version.
                $notice = UpdateNotice::get();

                return response()->json([
                    'ok' => true,
                    'skipped' => true,
                    'update_available' => $notice['available'],
                    'latest_version' => $notice['latest_version'],
                ]);
            }
        }

        // Health first — a click probes even during the back-off.
        $gate = $health->gate('update check', respectBackoff: $request->boolean('automatic'));
        if (! $gate['ok']) {
            return response()->json(['ok' => false, 'update_available' => false, 'message' => $gate['message'], 'retry_at' => $gate['retry_at']]);
        }

        $result = $client->checkForUpdate(config('subandl.version', '1.0.0'));
        UpdateNotice::record($result);
        ActivityLog::record('update-check', (bool) ($result['ok'] ?? false), ($result['ok'] ?? false)
            ? (($result['update_available'] ?? false) ? 'v' . $result['latest_version'] . ' available (checked by user).' : 'Up to date (checked by user).')
            : 'Update check failed: ' . ($result['message'] ?? 'unknown error') . '.');

        $state->last_update_check_at = now();
        $state->save();

        unset($result['raw']);

        return response()->json($result);
    }

    /**
     * Applies exactly ONE version step per call and reports whether another is
     * available, so the frontend drives a multi-version upgrade step by step
     * instead of one long request that can die mid-chain.
     */
    public function runUpdate(LicenseClient $client, UpdateApplier $applier)
    {
        if ($denied = $this->denyUnless('update') ?? $this->denyWithoutLicense()) {
            return $denied;
        }

        $lock = Cache::lock(SUBandL::UPDATE_LOCK, 30 * 60);

        if (! $lock->get()) {
            return response()->json([
                'status' => false,
                'running' => true,
                'message' => 'A software update is already running.',
            ], 409);
        }

        set_time_limit(0);

        $fromVersion = (string) config('subandl.version', '1.0.0');

        try {
            // Health-check the provider before touching files or the database.
            $probe = app(ServerHealth::class)->probe(task: 'update');
            if (! $probe['ok']) {
                return response()->json([
                    'status' => false,
                    'extracted' => false,
                    'update_available' => false,
                    'message' => $probe['message'],
                    'retry_at' => $probe['retry_at'],
                ], 503);
            }

            $result = $client->checkForUpdate($fromVersion);

            if ($result['locked'] ?? false) {
                return response()->json([
                    'status' => false,
                    'locked' => true,
                    'extracted' => false,
                    'update_available' => false,
                    'message' => 'This version needs an active monthly update subscription.',
                ], 402);
            }

            if (! ($result['ok'] ?? false) || ! ($result['update_available'] ?? false)) {
                return response()->json([
                    'status' => true,
                    'extracted' => false,
                    'update_available' => false,
                    'message' => 'No update available.',
                    'version' => $fromVersion,
                ]);
            }

            $applied = $applier->downloadAndApply($result);
            $stepToVersion = $applied['version'] ?? $result['latest_version'] ?? null;

            if (! ($applied['ok'] ?? false)) {
                return response()->json([
                    'status' => false,
                    'extracted' => (bool) ($applied['extracted'] ?? false),
                    'update_available' => false,
                    'message' => $applied['message'] ?? 'Update failed.',
                    'version' => $fromVersion,
                ], 422);
            }

            $next = $client->checkForUpdate((string) $stepToVersion);
            $updateAvailable = ($next['ok'] ?? false) && ($next['update_available'] ?? false);

            return response()->json([
                'status' => true,
                'extracted' => true,
                'update_available' => $updateAvailable,
                'next_version' => $updateAvailable ? ($next['latest_version'] ?? null) : null,
                'message' => "Update v{$stepToVersion} applied successfully.",
                'version' => $stepToVersion,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Step one of the update modal: is the provider reachable from this
     * server right now? A failure starts the 30-minute back-off.
     */
    public function updatePreflight(ServerHealth $health)
    {
        if ($denied = $this->denyUnless('update') ?? $this->denyWithoutLicense()) {
            return $denied;
        }

        $probe = $health->probe(task: 'update');

        return response()->json($probe, $probe['ok'] ? 200 : 503);
    }

    public function updateStatus(UpdateApplier $applier)
    {
        $state = LicenseState::current();

        return response()->json([
            'running' => $applier->isRunning($state),
            'last_update_status' => $state->last_update_status,
            'last_update_message' => $state->last_update_message,
            'version' => config('subandl.version', '1.0.0'),
        ]);
    }

    public function updateHistory()
    {
        $history = UpdateHistory::query()
            ->latest('id')
            ->limit(20)
            ->get(['id', 'ok', 'from_version', 'to_version', 'message', 'created_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'ok' => $row->ok,
                'from_version' => $row->from_version,
                'to_version' => $row->to_version,
                'message' => $row->message,
                'created_at' => $row->created_at?->toDateTimeString(),
            ]);

        return response()->json(['history' => $history]);
    }

    /**
     * The activity log (health checks, live checks, retries, skips) for the
     * Update & Backup page's history.
     */
    public function activity()
    {
        if (! Access::allows('update') && ! Access::allows('backup')) {
            return response()->json(['activity' => []]);
        }

        try {
            $rows = ActivityLog::query()->latest('id')->limit(40)->get(['id', 'type', 'ok', 'message', 'created_at']);
        } catch (\Throwable $e) {
            $rows = collect();
        }

        return response()->json(['activity' => $rows->map(fn ($row) => [
            'id' => $row->id,
            'type' => $row->type,
            'ok' => $row->ok,
            'message' => $row->message,
            'created_at' => $row->created_at?->toDateTimeString(),
        ])]);
    }

    /* ------------------------------------------------------------------ misc */

    /**
     * Clears caches and starts a live re-verification in the background, so the
     * button answers at once even while the provider is slow or down. The
     * returned license is the stored state, which a failed check never changes.
     */
    public function clearCache()
    {
        foreach (['cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $command) {
            Artisan::call($command);
        }

        BackgroundArtisan::dispatch('subandl:license-check', ['--force']);

        return response()->json([
            'status' => true,
            'message' => 'Cache cleared successfully',
            'license' => $this->formatStatus(LicenseState::current()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStatus(LicenseState $state): array
    {
        $backups = app(BackupService::class);

        return [
            'status' => $state->status,
            'license_key' => $state->license_key,
            'needs_subscription_redirect' => $state->needsSubscriptionRedirect(),
            'subscription_type' => $state->subscription_type,
            'client_name' => $state->client_name,
            'client_email' => $state->client_email,
            'expires_at' => optional($state->expires_at)->toDateTimeString(),
            'update_support_expires_at' => optional($state->update_support_expires_at)->toDateTimeString(),
            'updates_included' => $state->updatesIncluded(),
            'billing' => $state->billing(),
            'paid_through' => $state->billing()['paid_through'] ?? null,
            'next_due_date' => $state->billing()['next_due_date'] ?? null,
            'grace_days' => $state->grace_days,
            'in_grace_period' => $state->in_grace_period,
            'grace_ends_at' => optional($state->grace_ends_at)->toDateString(),
            'due_amount' => $state->due_amount,
            'monthly_fee' => $state->monthly_fee,
            'payment_info' => $state->payment_info,
            'backup_enabled' => $state->backup_enabled,
            'backup_running' => $backups->isRunning($state),
            'update_running' => app(UpdateApplier::class)->isRunning($state),
            'last_update_status' => $state->last_update_status,
            'last_update_message' => $state->last_update_message,
            'code_integrity_ok' => $state->code_integrity_ok,
            'current_version' => config('subandl.version', '1.0.0'),
            'last_verified_at' => optional($state->last_verified_at)->toDateTimeString(),
            'last_update_check_at' => optional($state->last_update_check_at)->toDateTimeString(),
            'last_backup_at' => optional($state->last_backup_at)->toDateTimeString(),
            'last_backup_status' => $state->last_backup_status,
            'last_backup_message' => $state->last_backup_message,
            'message' => $state->last_verification_error,
            'next_check_at' => $this->nextDueAt($state->last_verified_at, $state->needsAttention() ? (int) config('subandl.verify_cache_minutes_when_due', 30) : (int) config('subandl.verify_cache_minutes', 720))->toDateTimeString(),
            'next_update_check_at' => $this->nextDueAt($state->last_update_check_at, (int) config('subandl.update_check_hours', 2) * 60)->toDateTimeString(),
            'next_backup_at' => $backups->nextDueAt($state)->toDateTimeString(),
        ];
    }

    /**
     * Checks fire on the next request after the interval, not on a clock boundary,
     * so this is a "no earlier than" time.
     */
    private function nextDueAt(?Carbon $lastRunAt, int $intervalMinutes): Carbon
    {
        if (! $lastRunAt) {
            return now();
        }

        $due = $lastRunAt->copy()->addMinutes($intervalMinutes);

        return $due->isPast() ? now() : $due;
    }

    /**
     * The props every subscription page gets — identical for the Blade, Vue and
     * React UIs (and any custom Inertia page).
     */
    private function subscriptionProps(string $tab, bool $canUpdate, bool $canBackup): array
    {
        $state = LicenseState::current();

        return [
            'currentVersion' => config('subandl.version', '1.0.0'),
            'tab' => $tab,
            'canUpdate' => $canUpdate,
            'canBackup' => $canBackup,
            'licenseKey' => $state->license_key,
            'backupEnabled' => (bool) $state->backup_enabled,
            'backupIntervalHours' => BackupService::intervalHours(),
            // Blade only: let the global widget render the page as its panel.
            'widgetPage' => (bool) config('subandl.widget.enabled', true),
        ];
    }

    /**
     * blade   — the package's standalone Blade pages
     * vue     — Inertia pages SUBandL/* published with --tag=subandl-vue
     * react   — Inertia pages SUBandL/* published with --tag=subandl-react
     * inertia — your own Inertia pages, named in ui.pages
     */
    private function driver(): string
    {
        $driver = (string) config('subandl.ui.driver', 'blade');

        if ($driver !== 'blade' && ! class_exists(\Inertia\Inertia::class)) {
            return 'blade';
        }

        return $driver;
    }

    /**
     * JSON 403 for an action the user's access doesn't cover. The pages already
     * hide these buttons; this stops a direct request to the endpoint. Carries
     * both `status` and `ok`, the two result keys the endpoints use.
     */
    private function denyUnless(string $area): ?\Illuminate\Http\JsonResponse
    {
        if (Access::allows($area)) {
            return null;
        }

        return response()->json([
            'status' => false,
            'ok' => false,
            'message' => 'You do not have permission to do this.',
        ], 403);
    }

    /**
     * Update and backup work only with a valid license — the buttons are
     * disabled in the UI, and this refuses direct calls too.
     */
    private function denyWithoutLicense(): ?\Illuminate\Http\JsonResponse
    {
        if (LicenseState::current()->isUsable()) {
            return null;
        }

        return response()->json([
            'status' => false,
            'ok' => false,
            'unlicensed' => true,
            'message' => 'A valid license is required for updates and backups.',
        ], 403);
    }

    private function forbidden()
    {
        if ($this->driver() === 'inertia') {
            return \Inertia\Inertia::render(config('subandl.ui.pages.forbidden', 'Error/Forbidden'));
        }

        abort(403);
    }

    public static function homeUrl(): string
    {
        $home = config('subandl.home_route');

        return $home && Route::has($home) ? route($home) : url('/');
    }

    private const BUNDLED_PAGES = [
        'verification_required' => 'SUBandL/VerificationRequired',
        'terms' => 'SUBandL/Terms',
        'license' => 'SUBandL/Subscription',
        'update' => 'SUBandL/Subscription',
    ];

    private function render(string $page, string $view, array $props = [])
    {
        $props += [
            'base' => rtrim(url((string) config('subandl.routes.prefix', '')), '/'),
            'urls' => [
                'license' => route('subscription.license'),
                'update' => route('subscription.update'),
                'terms' => route('license.terms'),
                'home' => self::homeUrl(),
            ],
        ];

        return match ($this->driver()) {
            'inertia' => \Inertia\Inertia::render(config("subandl.ui.pages.{$page}"), $props),
            'vue', 'react' => \Inertia\Inertia::render(self::BUNDLED_PAGES[$page], $props),
            default => view($view, $props),
        };
    }
}
