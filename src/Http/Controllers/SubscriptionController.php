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
use SUBandL\Models\BackupHistory;
use SUBandL\Models\LicenseState;
use SUBandL\Models\UpdateHistory;
use SUBandL\SUBandL;
use SUBandL\Support\Access;
use SUBandL\Support\BackgroundArtisan;
use SUBandL\Update\UpdateApplier;

class SubscriptionController extends Controller
{
    /* ------------------------------------------------------------------ pages */

    public function verificationRequired()
    {
        $state = LicenseState::current();

        if ($state->isUsable()) {
            $home = config('subandl.home_route');

            return redirect($home && Route::has($home) ? route($home) : '/');
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

        return $this->render('license', 'subandl::subscription', [
            'currentVersion' => config('subandl.version', '1.0.0'),
            'tab' => 'license',
            'canUpdate' => Access::allows('update'),
            'canBackup' => Access::allows('backup'),
        ]);
    }

    public function subscriptionUpdate()
    {
        $canUpdate = Access::allows('update');
        $canBackup = Access::allows('backup');

        if (! $canUpdate && ! $canBackup) {
            return $this->forbidden();
        }

        return $this->render('update', 'subandl::subscription', [
            'currentVersion' => config('subandl.version', '1.0.0'),
            'tab' => $canUpdate ? 'update' : 'backup',
            'canUpdate' => $canUpdate,
            'canBackup' => $canBackup,
        ]);
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
        $state = LicenseState::current();

        if ($request->boolean('automatic')) {
            if (! $health->isHealthy()) {
                return response()->json(['ok' => false, 'update_available' => false, 'message' => 'Provider unreachable.']);
            }

            $hours = (int) config('subandl.update_check_hours', 2);
            if ($state->last_update_check_at && $state->last_update_check_at->gt(now()->subHours($hours))) {
                return response()->json(['ok' => true, 'skipped' => true, 'update_available' => false]);
            }
        }

        $result = $client->checkForUpdate(config('subandl.version', '1.0.0'));

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
            $result = $client->checkForUpdate($fromVersion);

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

    /* ------------------------------------------------------------------ misc */

    /**
     * Clears caches, then re-verifies in the same request so the cached license
     * state is fresh immediately.
     */
    public function clearCache(LicenseVerifier $verifier)
    {
        foreach (['cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $command) {
            Artisan::call($command);
        }

        $state = $verifier->refresh(force: true);

        return response()->json([
            'status' => true,
            'message' => 'Cache cleared successfully',
            'license' => $this->formatStatus($state),
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
            'next_check_at' => $this->nextDueAt($state->last_verified_at, (int) config('subandl.verify_cache_minutes', 720))->toDateTimeString(),
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

    private function forbidden()
    {
        if (config('subandl.ui.driver') === 'inertia' && class_exists(\Inertia\Inertia::class)) {
            return \Inertia\Inertia::render(config('subandl.ui.pages.forbidden', 'Error/Forbidden'));
        }

        abort(403);
    }

    private function render(string $page, string $view, array $props = [])
    {
        if (config('subandl.ui.driver') === 'inertia' && class_exists(\Inertia\Inertia::class)) {
            return \Inertia\Inertia::render(config("subandl.ui.pages.{$page}"), $props);
        }

        return view($view, $props + ['state' => LicenseState::current()]);
    }
}
