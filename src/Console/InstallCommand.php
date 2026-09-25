<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'subandl:install
        {--ui= : blade, vue or react (vue/react publish Inertia pages into resources/js)}
        {--no-migrate : Skip running the migration}';

    protected $description = 'Publish the SUBandL config and create its database tables.';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'subandl-config']);

        if ($ui = $this->option('ui')) {
            if (! in_array($ui, ['blade', 'vue', 'react'], true)) {
                $this->error('--ui must be blade, vue or react.');

                return self::FAILURE;
            }

            if ($ui !== 'blade') {
                $this->call('vendor:publish', ['--tag' => "subandl-{$ui}", '--force' => true]);
            }

            $this->setUiDriver($ui);
        }

        if (! $this->option('no-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        $this->newLine();
        $this->info('SUBandL installed. Next steps:');
        $this->line('  1. Set SUBANDL_SOFTWARE_SLUG (and optionally SUBANDL_LICENSE_KEY) in .env.');
        $this->line('  2. Open /subscription in the browser and save the license key.');
        $this->line('  3. Optional: add the system cron `* * * * * php artisan schedule:run`.');
        $this->line('     Without it, the web scheduler middleware triggers the checks from traffic.');
        if (in_array($this->option('ui'), ['vue', 'react'], true)) {
            $this->line('  Rebuild your frontend (npm run build) so Pages/SUBandL/* is included.');
        }
        $this->line('  Check anytime with: php artisan subandl:status');

        return self::SUCCESS;
    }

    private function setUiDriver(string $ui): void
    {
        $path = config_path('subandl.php');
        $content = (string) file_get_contents($path);
        $updated = preg_replace("/('ui'\s*=>\s*\[\s*'driver'\s*=>\s*)'[a-z]+'/", "\\1'{$ui}'", $content, 1);

        if ($updated !== null && $updated !== $content) {
            file_put_contents($path, $updated);
        }

        $this->info("UI driver set to '{$ui}' in config/subandl.php.");
    }
}
