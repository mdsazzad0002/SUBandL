<?php

namespace SUBandL\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use SUBandL\Backup\BackupService;
use SUBandL\License\LicenseClient;
use SUBandL\License\ServerHealth;
use SUBandL\Models\LicenseState;
use SUBandL\Support\Access;
use SUBandL\Support\Assets;
use SUBandL\Support\UpdateNotice;
use SUBandL\Update\UpdateApplier;

/**
 * Backs the global widget (resources/js/subandl/widget.js) that InjectWidget
 * adds to every page: one JSON snapshot for the payment reminder, update
 * notice and backup status, plus the widget's own JS/CSS files.
 */
class WidgetController extends Controller
{
    public function state(BackupService $backups, UpdateApplier $updater)
    {
        $state = LicenseState::current();
        $canUpdate = Access::allows('update');
        $canBackup = Access::allows('backup');

        if ($canUpdate) {
            $this->refreshUpdateNoticeWhenDue($state);
        }

        $due = (float) ($state->due_amount ?? 0);
        $notice = UpdateNotice::get();
        $lastSuccess = $canBackup ? $backups->lastSuccessfulAt() : null;

        return response()->json([
            'version' => (string) config('subandl.version', '1.0.0'),
            'urls' => [
                'subscription' => route('subscription.license'),
                'update' => route('subscription.update'),
            ],

            // Only while something is actually owed — no due, no card.
            'payment' => $due > 0 ? [
                'due_amount' => $state->due_amount,
                'monthly_fee' => $state->monthly_fee,
                'currency' => $state->payment_info['currency'] ?? 'BDT',
                'payment_info' => $state->payment_info,
                'grace_ends_at' => optional($state->grace_ends_at)->toDateString(),
                'expires_at' => optional($state->expires_at)->toDateTimeString(),
                'greeting' => array_values((array) config('subandl.widget.payment_greeting', [])),
            ] : null,

            'update' => $canUpdate ? [
                'available' => $notice['available'],
                'latest_version' => $notice['latest_version'],
                'changelog' => $notice['changelog'],
                'force_update' => $notice['force_update'],
                'checked_at' => $notice['checked_at'],
                'running' => $updater->isRunning($state),
                'support_expired' => $state->isMaintenanceExpired(),
            ] : null,

            'backup' => $canBackup ? [
                'enabled' => (bool) $state->backup_enabled,
                'running' => $backups->isRunning($state),
                'last_success_at' => $lastSuccess?->toDateTimeString(),
                'last_attempt_at' => optional($state->last_backup_at)->toDateTimeString(),
                'last_status' => $state->last_backup_status,
                'last_message' => $state->last_backup_message,
                'overdue' => $backups->isDailyBackupOverdue(),
                'next_at' => $backups->nextDueAt($state)->toDateTimeString(),
            ] : null,
        ]);
    }

    /**
     * Keeps the update notice fresh for installs where no scheduler runs: a
     * live check at most once per update_check_hours, only while updates can
     * actually be applied, and never two at once.
     */
    private function refreshUpdateNoticeWhenDue(LicenseState $state): void
    {
        $hours = (int) config('subandl.update_check_hours', 2);

        if ($state->last_update_check_at && $state->last_update_check_at->gt(now()->subHours($hours))) {
            return;
        }

        if (! $state->isUsable() || $state->isMaintenanceExpired() || ! app(ServerHealth::class)->isHealthy()) {
            return;
        }

        if (! Cache::add('subandl:widget-update-check', true, 60)) {
            return;
        }

        UpdateNotice::record(app(LicenseClient::class)->checkForUpdate());

        $state->last_update_check_at = now();
        $state->save();
    }

    public function asset(string $file)
    {
        $types = ['js' => 'application/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8'];
        $type = $types[pathinfo($file, PATHINFO_EXTENSION)] ?? null;
        $body = $type ? Assets::inline($file) : '';

        abort_if($body === '', 404);

        // The entry file is requested with ?v=<hash>; stamp the same hash onto its
        // relative import so a package update never mixes old and new modules.
        if (request()->filled('v')) {
            $body = str_replace("from './subandl.js'", "from './subandl.js?v=" . Assets::version() . "'", $body);
        }

        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => request()->filled('v') ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
