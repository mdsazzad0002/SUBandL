<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SUBandL\Backup\BackupService;
use SUBandL\Support\Platform;

class BackupCommand extends Command
{
    protected $signature = 'subandl:backup {--force : Run now, regardless of schedule or the customer toggle}
                                           {--before-update= : (internal) the backup an update started in the background, for that update run}';

    protected $description = 'Upload a database backup to the provider when due and enabled.';

    public function handle(BackupService $service): int
    {
        // A large dump + chunked upload can legitimately take minutes.
        Platform::allowLongRunning();

        $run = $this->option('before-update');
        $result = is_string($run) && $run !== ''
            ? $service->runBeforeUpdate($run)
            : $service->runBackup(force: (bool) $this->option('force'));

        if ($result['skipped'] ?? false) {
            $this->info('Backup skipped: ' . ($result['message'] ?? 'not due.'));

            return self::SUCCESS;
        }

        if ($result['ok'] ?? false) {
            $this->info('Backup uploaded successfully.');
        } else {
            $this->error('Backup failed: ' . ($result['message'] ?? 'unknown error'));
            Log::error('SUBandL: backup failed.', $result);
        }

        return self::SUCCESS;
    }
}
