<?php

namespace SUBandL;

use Illuminate\Support\Facades\Cache;
use SUBandL\Backup\BackupService;
use SUBandL\License\LicenseClient;
use SUBandL\License\LicenseVerifier;
use SUBandL\Models\LicenseState;
use SUBandL\Update\UpdateApplier;

/**
 * The public API most applications need, available as the `SUBandL` facade:
 *
 *   SUBandL::isValid();                  // license usable right now (cached, no HTTP)
 *   SUBandL::state()->expires_at;        // full cached license row
 *   SUBandL::module('careflow')['enabled'];
 *   SUBandL::verify(force: true);
 *   SUBandL::update();                   // apply the next pending version, if any
 *   SUBandL::backup(force: true);
 */
class SUBandL
{
    public const UPDATE_LOCK = 'subandl:update-running';

    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseVerifier $verifier,
        private readonly BackupService $backups,
        private readonly UpdateApplier $updater,
    ) {
    }

    public function version(): string
    {
        return (string) config('subandl.version', '1.0.0');
    }

    public function state(): LicenseState
    {
        return LicenseState::current();
    }

    public function isValid(): bool
    {
        return $this->state()->isUsable();
    }

    public function licenseKey(): ?string
    {
        return LicenseState::resolveLicenseKey();
    }

    public function verify(bool $force = false): LicenseState
    {
        return $this->verifier->refresh($force);
    }

    /**
     * Live entitlement check for an optional module (/api/{module}-status).
     *
     * @return array{ok: bool, enabled: bool, message: ?string}
     */
    public function module(string $module): array
    {
        return $this->client->checkModule($module);
    }

    public function checkUpdate(): array
    {
        return $this->client->checkForUpdate($this->version());
    }

    /**
     * Applies exactly ONE pending version step, under the lock shared by the
     * scheduled checker and the "Run Update" button.
     *
     * @return array{ok: bool, running?: bool, update_available?: bool, message?: string, version?: string}
     */
    public function update(): array
    {
        $lock = Cache::lock(self::UPDATE_LOCK, 30 * 60);

        if (! $lock->get()) {
            return ['ok' => false, 'running' => true, 'message' => 'A software update is already running.'];
        }

        try {
            // Never start changing files and the database against a provider
            // that can't be reached; automatic runs back off for 30+ minutes.
            $probe = app(License\ServerHealth::class)->probe(task: 'update');
            if (! $probe['ok']) {
                return ['ok' => false, 'update_available' => false, 'message' => $probe['message'], 'retry_at' => $probe['retry_at']];
            }

            $info = $this->checkUpdate();

            if (($info['locked'] ?? false)) {
                return ['ok' => false, 'locked' => true, 'update_available' => false, 'message' => 'This version needs an active monthly update subscription.'];
            }

            if (! ($info['ok'] ?? false) || ! ($info['update_available'] ?? false)) {
                return ['ok' => true, 'update_available' => false, 'message' => $info['message'] ?? 'No update available.', 'version' => $this->version()];
            }

            return $this->updater->downloadAndApply($info) + ['update_available' => true];
        } finally {
            $lock->release();
        }
    }

    public function backup(bool $force = false): array
    {
        return $this->backups->runBackup($force);
    }

    public function client(): LicenseClient
    {
        return $this->client;
    }
}
