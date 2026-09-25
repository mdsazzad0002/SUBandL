<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'subandl:install {--no-migrate : Only publish the config file}';

    protected $description = 'Publish the SUBandL config and create its database tables.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'subandl-config']);

        if (! $this->option('no-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        $this->newLine();
        $this->info('SUBandL installed. Next steps:');
        $this->line('  1. Set SUBANDL_SOFTWARE_SLUG (and optionally SUBANDL_LICENSE_KEY) in .env.');
        $this->line('  2. Open /subscription in the browser and save the license key.');
        $this->line('  3. Optional: add the system cron `* * * * * php artisan schedule:run`.');
        $this->line('     Without it, the web scheduler middleware triggers the checks from traffic.');
        $this->line('  Check anytime with: php artisan subandl:status');

        return self::SUCCESS;
    }
}
