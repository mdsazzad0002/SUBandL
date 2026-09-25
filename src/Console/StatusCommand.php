<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;
use SUBandL\License\InstallationIdentity;
use SUBandL\Models\LicenseState;

class StatusCommand extends Command
{
    protected $signature = 'subandl:status';

    protected $description = 'Show the cached license, update and backup state.';

    public function handle(): int
    {
        $state = LicenseState::current();
        $key = (string) $state->license_key;

        $this->table(['Field', 'Value'], [
            ['Provider', config('subandl.provider_url')],
            ['Software', config('subandl.software_slug')],
            ['Version', config('subandl.version')],
            ['Device ID', InstallationIdentity::current()->installation_uuid],
            ['License key', $key === '' ? '-' : substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4)],
            ['Status', $state->status . ($state->isUsable() ? ' (usable)' : ' (NOT usable)')],
            ['Subscription', $state->subscription_type ?? '-'],
            ['Expires', optional($state->expires_at)->toDateTimeString() ?? '-'],
            ['Updates included', $state->updatesIncluded() ? 'yes' : 'no'],
            ['Due', (string) ($state->due_amount ?? '0')],
            ['Last verified', optional($state->last_verified_at)->toDateTimeString() ?? 'never'],
            ['Last error', $state->last_verification_error ?? '-'],
            ['Last update check', optional($state->last_update_check_at)->toDateTimeString() ?? 'never'],
            ['Last update', trim(($state->last_update_status ?? '-') . ' ' . ($state->last_update_message ?? ''))],
            ['Backup enabled', $state->backup_enabled ? 'yes' : 'no'],
            ['Last backup', trim((optional($state->last_backup_at)->toDateTimeString() ?? 'never') . ' ' . ($state->last_backup_status ?? ''))],
        ]);

        return self::SUCCESS;
    }
}
