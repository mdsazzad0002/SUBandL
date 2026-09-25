<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SUBandL\License\ServerHealth;
use SUBandL\Models\LicenseState;
use SUBandL\SUBandL;
use SUBandL\Support\UpdateNotice;

class UpdateCheckCommand extends Command
{
    protected $signature = 'subandl:update-check {--force : Ignore the check interval}';

    protected $description = 'Check for a newer version (and apply it when update_auto_apply is on).';

    public function handle(SUBandL $subandl, ServerHealth $health): int
    {
        $force = (bool) $this->option('force');

        if (! $force && ! $health->isHealthy()) {
            $this->warn('Skipping update check: provider server is unreachable.');

            return self::SUCCESS;
        }

        $state = LicenseState::current();
        $hours = (int) config('subandl.update_check_hours', 2);

        if (! $force && $state->last_update_check_at && $state->last_update_check_at->gt(now()->subHours($hours))) {
            $this->info('Skipping update check: not due yet.');

            return self::SUCCESS;
        }

        if (! $state->isUsable()) {
            $this->warn('Skipping update check: license is not currently valid.');

            return self::SUCCESS;
        }

        if ($state->isMaintenanceExpired()) {
            $this->warn('Skipping update check: update/support period has expired.');

            return self::SUCCESS;
        }

        $state->last_update_check_at = now();
        $state->save();

        $check = $subandl->checkUpdate();
        UpdateNotice::record($check);

        // By default a new version is only announced (the widget's update modal);
        // the customer applies it. auto_apply installs it from here unattended.
        if (! config('subandl.update_auto_apply', false)) {
            if (! ($check['ok'] ?? false)) {
                $this->warn('Update check failed: ' . ($check['message'] ?? 'unknown error'));
            } elseif ($check['update_available'] ?? false) {
                $this->info('Update available: v' . ($check['latest_version'] ?? '?') . ' (waiting for the customer to apply it).');
            } else {
                $this->info('No update available.');
            }

            return self::SUCCESS;
        }

        $result = $subandl->update();

        if ($result['running'] ?? false) {
            $this->info('Skipping: an update is already running.');
        } elseif (! ($result['update_available'] ?? false)) {
            $this->info('No update available.');
        } elseif ($result['ok'] ?? false) {
            $this->info($result['message'] ?? 'Update applied.');
            Log::info('SUBandL: ' . ($result['message'] ?? 'update applied.'));
        } else {
            $this->error('Update failed: ' . ($result['message'] ?? 'unknown error'));
            Log::error('SUBandL: update failed.', $result);
        }

        return self::SUCCESS;
    }
}
