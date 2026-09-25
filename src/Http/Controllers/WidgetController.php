<?php

namespace SUBandL\Http\Controllers;

use Illuminate\Routing\Controller;
use SUBandL\Backup\BackupService;
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
    /**
     * Local data only — this runs on every page load, so it never contacts the
     * provider. The update notice is kept fresh by subandl:update-check, which
     * RunScheduledTasks / the scheduler start in the background.
     */
    public function state(BackupService $backups, UpdateApplier $updater, ServerHealth $health)
    {
        $state = LicenseState::current();
        $canUpdate = Access::allows('update');
        $canBackup = Access::allows('backup');

        $notice = UpdateNotice::get();
        $lastSuccess = $canBackup ? $backups->lastSuccessfulAt() : null;

        return response()->json([
            'version' => (string) config('subandl.version', '1.0.0'),
            'urls' => [
                'subscription' => route('subscription.license'),
                'update' => route('subscription.update'),
                'terms' => route('license.terms'),
                'home' => SubscriptionController::homeUrl(),
            ],

            // Which of the panel's tabs this user may use.
            'access' => [
                'license' => Access::allows('license'),
                'update' => $canUpdate,
                'backup' => $canBackup,
            ],
            'backup_interval_hours' => BackupService::intervalHours(),

            // Sends a tab left open to the subscription page once the cached
            // license turns unusable (the middleware only sees navigations).
            'license' => [
                'needs_redirect' => $state->needsSubscriptionRedirect(),
                'redirect_url' => route($state->redirectRouteName()),
                'allowed_paths' => array_values((array) config('subandl.allowed_when_invalid', [])),
            ],

            // Set for one browser per ServerHealth window: it checks whether the
            // provider is reachable and reports back (see ServerHealth).
            'health_check_url' => $health->claimBrowserCheck() ? config('subandl.provider_url') : null,

            // Only while something is owed or the payment is late — no due, no card.
            'payment' => $this->payment($state),

            'update' => $canUpdate ? [
                'available' => $notice['available'],
                'latest_version' => $notice['latest_version'],
                'version_title' => $notice['version_title'],
                'changelog' => $notice['changelog'],
                'force_update' => $notice['force_update'],
                // Lifetime plan without a paid update subscription: the new
                // version is shown with its price but cannot be installed.
                'locked' => $notice['locked'] || ($notice['available'] && ! $state->updatesIncluded()),
                'monthly_fee' => $notice['monthly_fee'] ?? $state->monthly_fee,
                'currency' => $state->billing()['currency'] ?? ($state->payment_info['currency'] ?? 'BDT'),
                'checked_at' => $notice['checked_at'],
                'running' => $updater->isRunning($state),
                'updates_included' => $state->updatesIncluded(),
                'retry_at' => optional($health->backoffUntil())->toDateTimeString(),
                // The last server-side health ping before a live call.
                'health' => $health->lastProbe(),
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
     * The payment reminder: amount, deadline and how to pay. `late` means the
     * paid-through date has passed and the grace period is running (or over).
     *
     * @return array<string, mixed>|null
     */
    private function payment(LicenseState $state): ?array
    {
        $billing = $state->billing();
        $due = (float) ($state->due_amount ?? 0);
        $late = $state->isPaymentLate() || $state->status === 'expired';

        if ($due <= 0 && ! $late) {
            return null;
        }

        return [
            'due_amount' => $state->due_amount,
            'monthly_fee' => $state->monthly_fee,
            'currency' => $billing['currency'] ?? ($state->payment_info['currency'] ?? 'BDT'),
            'payment_info' => $state->payment_info,
            'plan' => $billing['plan'] ?? $state->subscription_type,
            'late' => $late,
            'blocked' => ! $state->isUsable(),
            'in_grace' => (bool) $state->in_grace_period,
            'grace_ends_at' => optional($state->grace_ends_at)->toDateString() ?? ($billing['grace_ends_at'] ?? null),
            'next_due_date' => $billing['next_due_date'] ?? null,
            'paid_through' => $billing['paid_through'] ?? optional($state->expires_at)->toDateString(),
            'expires_at' => optional($state->expires_at)->toDateTimeString(),
            'invoices' => array_values((array) ($billing['invoices'] ?? [])),
            'greeting' => array_values((array) config('subandl.widget.payment_greeting', [])),
        ];
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
