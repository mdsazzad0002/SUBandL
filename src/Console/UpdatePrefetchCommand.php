<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;
use SUBandL\SUBandL;
use SUBandL\Update\UpdateApplier;

/**
 * Downloads the next version's package in the background (started by every
 * update check that finds one), so "Update now" only has to install it.
 */
class UpdatePrefetchCommand extends Command
{
    protected $signature = 'subandl:update-prefetch
        {--check : Ask the provider again instead of using the last check}
        {--for-update : Download for the update now running (its chunks follow this process)}';

    protected $description = 'Download the next update package in the background, ready to install.';

    public function handle(UpdateApplier $applier, SUBandL $subandl): int
    {
        if ($this->option('for-update')) {
            $result = $applier->prefetch(null, forUpdate: true);
            $this->{($result['ok'] ?? false) ? 'info' : 'warn'}($result['message'] ?? 'Done.');

            return self::SUCCESS;
        }

        $result = $this->option('check') ? $this->fresh($applier, $subandl) : $applier->prefetch();

        // The saved answer is missing, or its (possibly signed) link no longer
        // works: ask the provider once more for a current one.
        if (! ($result['ok'] ?? false) && ! $this->option('check')) {
            $result = $this->fresh($applier, $subandl) ?? $result;
        }

        $this->{($result['ok'] ?? false) ? 'info' : 'warn'}($result['message'] ?? 'Done.');

        return self::SUCCESS;
    }

    private function fresh(UpdateApplier $applier, SUBandL $subandl): ?array
    {
        $check = $subandl->checkUpdate();
        if (! ($check['ok'] ?? false) || ! ($check['update_available'] ?? false) || ($check['locked'] ?? false)) {
            return ['ok' => true, 'message' => 'No update to download.'];
        }

        return $applier->prefetch($check);
    }
}
