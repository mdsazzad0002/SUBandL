<?php

namespace SUBandL\License;

use Illuminate\Support\Str;
use SUBandL\Models\LicenseInstallation;

/**
 * Each deployment gets its own uuid + signing secret, generated once and stored
 * in its own database. The uuid is sent as `device_id`, which the provider binds
 * the license key to — copying the key alone to another server is not enough.
 */
class InstallationIdentity
{
    public static function current(): LicenseInstallation
    {
        $installation = LicenseInstallation::query()->first();

        if ($installation) {
            return $installation;
        }

        return LicenseInstallation::create([
            'installation_uuid' => (string) Str::uuid(),
            'signing_secret' => Str::random(64),
            'domain' => app()->runningInConsole() ? null : request()->getHost(),
        ]);
    }
}
