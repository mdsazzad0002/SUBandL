<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;
use SUBandL\Backup\DumpBinary;
use SUBandL\License\IdentityWatch;
use SUBandL\License\InstallationIdentity;
use SUBandL\Models\LicenseState;
use SUBandL\Support\BackgroundArtisan;
use SUBandL\Support\CliPhpBinary;
use SUBandL\Support\DeviceSerial;
use SUBandL\Support\HttpClient;
use SUBandL\Support\Platform;

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
            ['Device serial', DeviceSerial::get() ?? 'unavailable (host name + folder used)'],
            ['Licensed domain', IdentityWatch::knownDomain() ?? 'not bound yet'],
            ['Tamper', IdentityWatch::tamperReason() ?? 'none (browser-side check runs per request)'],
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

        $this->newLine();
        $this->line('<comment>Environment</comment>');
        $this->table(['Check', 'Value'], $this->environment());

        return self::SUCCESS;
    }

    /** What the package will use on this host — the first thing to look at when something doesn't run. */
    private function environment(): array
    {
        $yes = fn (bool $ok) => $ok ? 'yes' : '<fg=red>no</>';
        $driver = config('database.connections.' . (config('subandl.backup.connection') ?: config('database.default')) . '.driver');

        $dump = match ($driver) {
            'mysql', 'mariadb' => DumpBinary::find((string) config('subandl.backup.mysqldump_binary', 'mysqldump'), ['mysqldump', 'mariadb-dump'], 'mysql')
                ?? (config('subandl.backup.php_fallback', true) ? 'not found — built-in PHP dump will be used' : '<fg=red>not found</>'),
            'pgsql' => DumpBinary::find((string) config('subandl.backup.pg_dump_binary', 'pg_dump'), ['pg_dump'], 'pgsql') ?? '<fg=red>pg_dump not found</>',
            'sqlite' => 'file copy',
            default => "unsupported driver '{$driver}'",
        };

        $verify = HttpClient::verify();

        return [
            ['OS', PHP_OS_FAMILY . ' (' . php_uname('s') . ' ' . php_uname('r') . ')'],
            ['PHP', PHP_VERSION . ' / ' . PHP_SAPI],
            ['CLI php for tasks', CliPhpBinary::resolve() . (trim((string) config('subandl.php_binary')) !== '' ? ' (configured)' : '')],
            ['Background tasks', BackgroundArtisan::method()],
            ['proc_open', $yes(Platform::functionEnabled('proc_open'))],
            ['Database dump', $dump],
            ['HTTPS CA bundle', $verify === true ? 'system / php.ini' : ($verify === false ? '<fg=red>verification OFF</>' : $verify)],
            ['zip extension (updates)', $yes(class_exists(\ZipArchive::class))],
            ['Storage writable', $yes(is_writable(storage_path('app')))],
        ];
    }
}
