<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;
use SUBandL\License\LicenseVerifier;

class LicenseCheckCommand extends Command
{
    protected $signature = 'subandl:license-check {--force : Bypass the throttle and the reachability check}';

    protected $description = 'Verify the software license with the provider.';

    public function handle(LicenseVerifier $verifier): int
    {
        $state = $verifier->refresh(force: (bool) $this->option('force'));

        $this->info("License status: {$state->status}");

        return self::SUCCESS;
    }
}
