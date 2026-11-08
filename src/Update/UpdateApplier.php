<?php

namespace SUBandL\Update;

use Illuminate\Support\Facades\Artisan;
use SUBandL\Backup\BackupService;
use SUBandL\Events\UpdateFinished;
use SUBandL\Models\ActivityLog;
use SUBandL\Models\LicenseState;
use SUBandL\Models\UpdateHistory;
use SUBandL\Support\CliPhpBinary;
use SUBandL\Support\HttpClient;
use SUBandL\Support\Platform;
use SUBandL\Support\UpdateNotice;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Downloads and applies a single update package (one version step). A backup is
 * taken first when one is due per BackupService's own schedule, and every step
 * is reported, so an update is inspectable rather than a black-box operation.
 * Each stage (backup, download, merge, migrate) publishes its own percentage
 * through UpdateProgress while it runs.
 *
 * Runs as a resumable job (staging/job.json): the browser drives it one
 * short request at a time (see begin()/step()), the CLI in one go.
 *
 * Kept fast on purpose: a package already downloaded (and verified) by an
 * earlier attempt is reused, and the merge skips every file whose content is
 * already identical (same size + CRC32), so only what changed is written.
 *
 * Release zip contract: paths are relative to base_path(). Optionally the zip
 * may contain config('subandl.update.remove_manifest') at its root, listing one
 * relative path per line to delete after extraction.
 */
class UpdateApplier
{
    private const CORRUPT = 'the archive entry is corrupt';

    /** Seconds without a chunk before a browser-driven job counts as abandoned. */
    private const JOB_IDLE_SECONDS = 120;

    /** Folders already known to exist during this merge. @var array<string, true> */
    private array $knownDirs = [];

    public function __construct(private readonly BackupService $backupService)
    {
    }

    /**
     * Whether an update step is currently in flight, per the flag set at the start of
     * downloadAndApply(). A flag older than stale_minutes belongs to a request that died
     * (worker timeout/kill) and is reset instead of locking the customer out.
     */
    public function isRunning(?LicenseState $state = null): bool
    {
        $state ??= LicenseState::current();

        if (! $state->update_running) {
            return false;
        }

        $staleMinutes = (int) config('subandl.stale_minutes', 30);
        if ($state->update_started_at && $state->update_started_at->lt(now()->subMinutes($staleMinutes))) {
            $recovered = LicenseState::query()
                ->whereKey($state->getKey())
                ->where('update_running', true)
                ->update([
                    'update_running' => false,
                    'last_update_status' => 'failed',
                    'last_update_message' => 'Previous update step was interrupted before it could finish and has been reset.',
                ]);

            if ($recovered) {
                // update() bypasses model events, so drop the cached row by hand.
                $state->forceFill(['update_running' => false])->save();
            }

            return false;
        }

        return true;
    }

    /**
     * The whole step in one go — the scheduler, the facade and the CLI, where
     * no web server timeout applies. Same job as the browser's, just with no
     * time budget, so both paths share every stage.
     */
    public function downloadAndApply(array $updateInfo): array
    {
        if ($this->activeJob()) {
            return ['ok' => false, 'running' => true, 'message' => 'An update is already being installed from the browser.'];
        }

        // The scheduler / facade path has no check stage of its own.
        if (! UpdateProgress::active()) {
            UpdateProgress::start((string) config('subandl.version', '1.0.0'), $updateInfo['latest_version'] ?? null);
            UpdateProgress::done('check', 'v' . ($updateInfo['latest_version'] ?? '?') . ' found.');
        }

        $run = 'cli-' . bin2hex(random_bytes(4));
        $this->begin($run, $updateInfo);

        do {
            $step = $this->step($run);
        } while (! $step['finished']);

        return $step['result'];
    }

    /**
     * Opens a resumable update job for one version step. The browser then
     * calls step() once per request until it reports finished — each call
     * does at most chunk_seconds of work, so no single request runs into a
     * web server / FastCGI timeout (Laragon, WAMP, IIS: 30–60s), and a
     * single-threaded server (`php artisan serve` on Windows) answers the
     * progress poll between chunks instead of only at the very end.
     */
    public function begin(string $run, array $updateInfo): void
    {
        LicenseState::current()->forceFill(['update_running' => true, 'update_started_at' => now()])->save();
        UpdateProgress::target($updateInfo['latest_version'] ?? null);

        $this->saveJob([
            'format' => 1,
            'run' => $run,
            'phase' => 'backup',
            'info' => $updateInfo,
            'from_version' => (string) config('subandl.version', '1.0.0'),
            'touched_at' => time(),
        ]);
    }

    /** Whether $run is the job in progress (the browser's next chunk). */
    public function hasJob(string $run): bool
    {
        $job = $this->loadJob();

        return $run !== '' && $job !== null && ($job['run'] ?? null) === $run;
    }

    /**
     * The job a browser is driving right now — one that had a chunk within
     * the last two minutes. An older one was abandoned (tab closed) and may
     * be replaced by a new run; everything it did is safe to redo.
     */
    public function activeJob(): ?array
    {
        $job = $this->loadJob();

        return $job !== null && (int) ($job['touched_at'] ?? 0) >= time() - self::JOB_IDLE_SECONDS ? $job : null;
    }

    /**
     * Advances the job by up to $budgetSeconds (null: until it is done).
     *
     * @return array{finished: bool, result?: array}
     */
    public function step(string $run, ?float $budgetSeconds = null): array
    {
        $job = $this->loadJob();
        if ($job === null || ($job['run'] ?? null) !== $run) {
            return ['finished' => true, 'result' => ['ok' => false, 'message' => 'This update is no longer in progress. Start it again.']];
        }

        UpdateProgress::resume($run);
        // Heartbeat: a chunked update outlasting stale_minutes is still alive.
        LicenseState::current()->forceFill(['update_running' => true, 'update_started_at' => now()])->save();

        $deadline = $budgetSeconds === null ? INF : microtime(true) + max(0.01, $budgetSeconds);

        try {
            $result = $this->advance($job, $deadline);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => 'Update failed: ' . $e->getMessage()];
            UpdateProgress::failCurrent($result['message']);
        }

        if ($result === null) {
            $job['touched_at'] = time();
            $this->saveJob($job);

            return ['finished' => false];
        }

        $this->clearJob();

        return ['finished' => true, 'result' => $this->finish($job, $result)];
    }

    /**
     * Runs stages until the job is done (the result) or the budget is used up
     * (null). Backup and migrations cannot be split, so each starts in a
     * request of its own with the full budget ahead of it.
     */
    private function advance(array &$job, float $deadline): ?array
    {
        $chunked = $deadline !== INF;
        $worked = false;

        while (true) {
            if ($worked && microtime(true) >= $deadline) {
                return null;
            }

            switch ($job['phase']) {
                case 'backup':
                    if (empty($job['info']['download_url'])) {
                        UpdateProgress::fail('download', 'No download URL provided.');

                        return ['ok' => false, 'message' => 'No download URL provided by update server.'];
                    }

                    // Not forced — only actually backs up when one is due (or none ever ran).
                    $backup = $this->preUpdateBackup($job, $deadline);
                    if ($backup === null) {
                        return null;
                    }
                    if (! ($backup['ok'] ?? false)) {
                        UpdateProgress::fail('backup', $backup['message'] ?? 'Backup failed.');

                        return ['ok' => false, 'message' => 'Aborting update: pre-update backup failed. ' . ($backup['message'] ?? '')];
                    }
                    $job['backup_id'] = $backup['backup_id'] ?? null;
                    ($backup['skipped'] ?? false)
                        ? UpdateProgress::skip('backup', $backup['message'] ?? 'Not due.')
                        : UpdateProgress::done('backup', 'Backup uploaded.');

                    if (! class_exists(ZipArchive::class)) {
                        UpdateProgress::fail('download', 'The PHP zip extension is not enabled.');

                        return ['ok' => false, 'message' => 'The PHP zip extension is not enabled. Enable it in php.ini (extension=zip) and restart the web server.'];
                    }

                    $stagingDir = $this->stagingDir();
                    if (! is_dir($stagingDir) && ! @mkdir($stagingDir, 0755, true) && ! is_dir($stagingDir)) {
                        UpdateProgress::fail('download', 'Could not create the staging folder.');

                        return ['ok' => false, 'message' => "Could not create the update staging folder '{$stagingDir}'."];
                    }

                    $job['phase'] = 'download';
                    break;

                case 'download':
                    $download = $this->fetchPackage($job, $deadline);
                    if ($download === null) {
                        return null;
                    }
                    if (! $download['ok']) {
                        UpdateProgress::fail('download', $download['message']);

                        return ['ok' => false, 'message' => $download['message']];
                    }
                    $job['phase'] = 'merge';
                    break;

                case 'merge':
                    $merge = $this->mergePackage($job, $deadline);
                    if ($merge === null) {
                        return null;
                    }
                    if (! $merge['ok']) {
                        UpdateProgress::fail('merge', $merge['message']);

                        return [
                            'ok' => false,
                            'extracted' => ($merge['extracted'] ?? 0) > 0,
                            'message' => isset($merge['extracted'])
                                ? 'Update package did not extract cleanly: ' . $merge['message']
                                : $merge['message'],
                            'backup_path' => $job['backup_id'] ?? null,
                        ];
                    }

                    $job['removed'] = $this->applyRemoveManifest();
                    UpdateProgress::done('merge', $this->mergeSummary($merge) . ($job['removed'] ? ', ' . count($job['removed']) . ' removed' : '') . '.');
                    $job['phase'] = 'migrate';
                    break;

                case 'migrate':
                    if ($chunked && $worked) {
                        return null;
                    }

                    $job['commands'] = array_values(array_filter(array_merge(
                        is_array($job['info']['commands'] ?? null) ? $job['info']['commands'] : [],
                        (array) config('subandl.update.after_update_commands', []),
                    ), fn ($c) => is_string($c) && trim($c) !== ''));

                    $migration = $this->runMigrations(count($job['commands']) > 0);
                    $job['migration_output'] = mb_substr($migration['output'], -20000);
                    if (! $migration['ok']) {
                        UpdateProgress::fail('migrate', $migration['message']);

                        return [
                            'ok' => false,
                            'extracted' => true,
                            'message' => 'Update files applied but migrations failed: ' . $migration['message'],
                            'backup_path' => $job['backup_id'] ?? null,
                            'migration_output' => $job['migration_output'],
                        ];
                    }

                    $job['migrate_summary'] = $migration['summary'];
                    $job['command_index'] = 0;
                    $job['commands_output'] = '';
                    $job['phase'] = 'commands';
                    break;

                case 'commands':
                    $commands = $job['commands'] ?? [];
                    $ranOne = false;
                    while ($job['command_index'] < count($commands)) {
                        if ($chunked && ($worked || $ranOne) && microtime(true) >= $deadline) {
                            return null;
                        }

                        $command = $commands[$job['command_index']];
                        UpdateProgress::stage('migrate', 85 + 15 * $job['command_index'] / max(1, count($commands)), "Running {$command}…");
                        $result = $this->runArtisanCommands([$command]);
                        $job['commands_output'] .= $result['output'];
                        if (! $result['ok']) {
                            UpdateProgress::fail('migrate', $result['message']);

                            return [
                                'ok' => false,
                                'extracted' => true,
                                'message' => 'Update files applied but a post-update command failed: ' . $result['message'],
                                'backup_path' => $job['backup_id'] ?? null,
                                'migration_output' => $job['migration_output'] ?? '',
                                'commands_output' => $job['commands_output'],
                            ];
                        }
                        $job['command_index']++;
                        $ranOne = true;
                    }

                    $info = $job['info'];
                    if (! empty($info['latest_version'])) {
                        $this->updateEnvVersion((string) $info['latest_version']);
                    }
                    UpdateProgress::done('migrate', $job['migrate_summary'] ?? 'Done.');

                    return [
                        'ok' => true,
                        'extracted' => true,
                        'message' => 'Update v' . ($info['latest_version'] ?? '') . ' applied successfully.',
                        'backup_path' => $job['backup_id'] ?? null,
                        'version' => $info['latest_version'] ?? null,
                        'removed' => $job['removed'] ?? [],
                        'migration_output' => $job['migration_output'] ?? '',
                        'commands_output' => $job['commands_output'],
                    ];

                default:
                    return ['ok' => false, 'message' => 'Unknown update stage. Start the update again.'];
            }

            $worked = true;
        }
    }

    /** Records the outcome of a finished job (history, activity log, event). */
    private function finish(array $job, array $result): array
    {
        $fromVersion = (string) ($job['from_version'] ?? config('subandl.version', '1.0.0'));
        $updateInfo = $job['info'] ?? [];
        $ok = (bool) ($result['ok'] ?? false);

        // A verified package is kept for the retry (cleaned after a day); a
        // partial download is not resumed by a new job, so it goes.
        $download = $job['download'] ?? [];
        if (! empty($download['part'])) {
            @unlink($download['part']);
            @unlink($download['part'] . '.chunk');
        }
        if (! empty($download['zip']) && ($ok || ! ($download['verified'] ?? false))) {
            @unlink($download['zip']);
        }

        LicenseState::current()->forceFill(['update_running' => false])->save();

        $toVersion = $result['version'] ?? $updateInfo['latest_version'] ?? null;
        $result['from_version'] = $fromVersion;

        $state = LicenseState::current();
        $state->last_update_status = $ok ? 'success' : 'failed';
        $state->last_update_message = $result['message'] ?? null;
        $state->save();

        UpdateHistory::create([
            'ok' => $ok,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'message' => $ok ? "Applied update to v{$toVersion}." : ($result['message'] ?? 'Update failed.'),
        ]);

        if (! $ok) {
            // The full console output (the message only carries the extracted
            // reason) — shown in the activity log and written to laravel.log.
            $output = trim(($result['migration_output'] ?? '') . "\n" . ($result['commands_output'] ?? ''));
            ActivityLog::record('update', false, $result['message'] ?? 'Update failed.', array_filter([
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'output' => $output !== '' ? mb_substr($output, -8000) : null,
            ]));
        }

        if ($ok) {
            UpdateNotice::clear();
        }

        event(new UpdateFinished($ok, $fromVersion, $toVersion, $result));

        return $result;
    }

    /**
     * The backup before an update. A backup already running in another process
     * (the scheduler, the browser's auto-check, the button) is waited for (up
     * to backup_wait_minutes, default 10) — and when that backup succeeded it
     * is the pre-update backup, since the schedule then reports the next one
     * as not due. In chunked mode the wait is spread over several requests.
     *
     * @return array|null null while still waiting for the other backup
     */
    private function preUpdateBackup(array &$job, float $deadline): ?array
    {
        $waitSeconds = 60 * (int) config('subandl.update.backup_wait_minutes', 10);

        while (true) {
            if ($this->backupService->isRunning()) {
                $job['backup_waited'] = true;
                $since = $job['backup_wait_since'] ??= time();
                $left = $waitSeconds - (time() - $since);
                $slice = $deadline === INF ? $left : (int) min($left, max(1, ceil($deadline - microtime(true))));

                $idle = $left > 0 && $this->backupService->waitUntilIdle($slice, function () use ($since) {
                    $waited = time() - $since;
                    UpdateProgress::stage('backup', 0, 'Waiting for the backup already running to finish… (' . gmdate($waited >= 3600 ? 'G:i:s' : 'i:s', $waited) . ')');
                });

                if (! $idle) {
                    return $left <= $slice
                        ? ['ok' => false, 'message' => 'A backup is still running. Try the update again once it has finished.']
                        : null;
                }
            }

            UpdateProgress::stage('backup', 0, 'Checking whether a backup is due…');
            $result = $this->backupService->runBackup(beforeUpdate: true);
            $job['backup_attempts'] = ($job['backup_attempts'] ?? 0) + 1;

            // Another process claimed the backup between the check and the claim: wait for that one.
            if (! ($result['running'] ?? false) || $job['backup_attempts'] >= 3) {
                return ($job['backup_waited'] ?? false) && ($result['skipped'] ?? false) && ($result['ok'] ?? false)
                    ? ['message' => 'Used the backup that just finished.'] + $result
                    : $result;
            }
        }
    }

    /**
     * Gets the package into staging: reuses one an earlier attempt already
     * downloaded when its checksum still matches, otherwise streams it to disk
     * — in one request on the CLI, or in Range-request slices of
     * download_chunk_bytes appended to a .part file in chunked mode (a server
     * that ignores Range just sends the whole file in the first slice).
     *
     * @return array{ok: bool, message?: string}|null null when more is left for the next chunk
     */
    private function fetchPackage(array &$job, float $deadline): ?array
    {
        if (! isset($job['download'])) {
            $info = $job['info'];
            $expected = is_string($info['checksum'] ?? null) && $info['checksum'] !== ''
                ? strtolower($info['checksum'])
                : null;
            $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string) ($info['latest_version'] ?? '')) ?: 'unknown';
            $stagingDir = $this->stagingDir();

            $this->cleanupStaging($stagingDir);

            // Only a package with a known checksum can be trusted on a second run.
            $zipPath = $stagingDir . DIRECTORY_SEPARATOR . ($expected
                ? "update-{$version}-" . substr($expected, 0, 16) . '.zip'
                : "update-{$version}-" . now()->format('YmdHis') . '.zip');

            if ($expected && is_file($zipPath)) {
                UpdateProgress::stage('download', 50, 'Verifying the package downloaded earlier…');
                if (hash_equals($expected, hash_file('sha256', $zipPath))) {
                    $job['download'] = ['zip' => $zipPath, 'verified' => true];
                    UpdateProgress::done('download', 'Reused the package downloaded earlier (' . self::bytes(filesize($zipPath)) . ').');

                    return ['ok' => true];
                }
                @unlink($zipPath);
            }

            $job['download'] = [
                'url' => (string) $info['download_url'],
                'zip' => $zipPath,
                'part' => $zipPath . '.part',
                'expected' => $expected,
                'offset' => 0,
                'total' => (int) ($info['size'] ?? $info['file_size'] ?? 0),
                'verified' => false,
            ];
            @unlink($job['download']['part']);
            UpdateProgress::stage('download', 0, 'Connecting…');
        }

        $d = &$job['download'];
        if (! isset($d['part'])) {
            return ['ok' => true]; // reused package
        }

        try {
            $received = $deadline === INF ? $this->downloadWhole($d) : $this->downloadSlices($d, $deadline);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'message' => 'Update package download failed: ' . $e->getMessage()];
        }

        if ($received !== true) {
            return $received; // null (more to come) or the failure
        }

        UpdateProgress::stage('download', 97, 'Verifying checksum…');
        if ($d['expected'] && ! hash_equals($d['expected'], hash_file('sha256', $d['part']))) {
            return ['ok' => false, 'message' => 'Update package checksum mismatch — download is corrupt or was tampered with.'];
        }

        if (! @rename($d['part'], $d['zip'])) {
            return ['ok' => false, 'message' => "Could not move the downloaded package into '{$this->stagingDir()}'."];
        }

        $d['verified'] = $d['expected'] !== null;
        unset($d['part']);
        UpdateProgress::done('download', 'Downloaded ' . self::bytes(filesize($d['zip'])) . '.');

        return ['ok' => true];
    }

    /** @return true|array true when the whole package is in the .part file, else the failure */
    private function downloadWhole(array &$d): bool|array
    {
        $response = $this->getPackage($d['url'], $d['part'], function ($total, $received) use ($d) {
            $total = $total ?: $d['total'];
            UpdateProgress::stage(
                'download',
                $total > 0 ? 95 * $received / $total : 0,
                $total > 0 ? self::bytes($received) . ' of ' . self::bytes($total) : self::bytes($received) . ' received'
            );
        });

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Update package download failed (HTTP ' . $response->status() . ').'];
        }

        return true;
    }

    /** @return true|array|null true when complete, null when the budget ran out first, else the failure */
    private function downloadSlices(array &$d, float $deadline): bool|array|null
    {
        $sliceBytes = max(256 * 1024, (int) config('subandl.update.download_chunk_bytes', 8 * 1024 * 1024));
        $slicePath = $d['part'] . '.chunk';

        while (true) {
            $from = (int) $d['offset'];
            $response = $this->getPackage($d['url'], $slicePath, function ($total, $received) use ($d, $from) {
                $all = (int) $d['total'];
                $got = $from + $received;
                UpdateProgress::stage(
                    'download',
                    $all > 0 ? 95 * $got / $all : 0,
                    $all > 0 ? self::bytes($got) . ' of ' . self::bytes($all) : self::bytes($got) . ' received'
                );
            }, ['Range' => 'bytes=' . $from . '-' . ($from + $sliceBytes - 1)]);

            $status = $response->status();
            clearstatcache();
            $size = (int) @filesize($slicePath);

            if ($status === 206) {
                if (! preg_match('#bytes\s+(\d+)-(\d+)/(\d+|\*)#i', (string) $response->header('Content-Range'), $m) || (int) $m[1] !== $from) {
                    @unlink($slicePath);

                    return ['ok' => false, 'message' => 'Update package download failed (the server sent an unexpected byte range).'];
                }

                if (! $this->appendFile($slicePath, $d['part'])) {
                    return ['ok' => false, 'message' => "Could not write the download into '{$this->stagingDir()}'."];
                }
                $d['offset'] = $from + $size;
                if ($m[3] !== '*') {
                    $d['total'] = (int) $m[3];
                }
                $complete = $d['total'] > 0 ? $d['offset'] >= $d['total'] : $size < $sliceBytes;
            } elseif ($status === 200) {
                // Range not supported: this one response is the whole package.
                @unlink($d['part']);
                if (! @rename($slicePath, $d['part'])) {
                    return ['ok' => false, 'message' => "Could not write the download into '{$this->stagingDir()}'."];
                }
                $d['offset'] = $d['total'] = $size;
                $complete = true;
            } elseif ($status === 416 && $from > 0) {
                // Asked past the end: everything is already here.
                @unlink($slicePath);
                $complete = true;
            } else {
                @unlink($slicePath);

                return ['ok' => false, 'message' => 'Update package download failed (HTTP ' . $status . ').'];
            }

            if ($complete) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
        }
    }

    /**
     * One GET streamed to $sink, tried at least twice on a connection error
     * or 5xx before giving up.
     *
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    private function getPackage(string $url, string $sink, callable $progress, array $headers = []): \Illuminate\Http\Client\Response
    {
        $attempts = max(2, (int) config('subandl.live_attempts', 2));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = HttpClient::request()->timeout((int) config('subandl.update.download_timeout', 300))
                    ->withHeaders($headers)
                    ->withOptions(['progress' => $progress])
                    ->sink($sink)
                    ->get($url);

                if (! $response->serverError() || $attempt >= $attempts) {
                    return $response;
                }
                $reason = 'HTTP ' . $response->status();
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt >= $attempts) {
                    ActivityLog::record('update', false, "Package download failed after {$attempt} attempts: {$e->getMessage()}");
                    throw $e;
                }
                $reason = $e->getMessage();
            }

            ActivityLog::record('update', false, "Package download attempt {$attempt} failed ({$reason}); retrying.");
            UpdateProgress::stage('download', 0, "Attempt {$attempt} failed ({$reason}); retrying…");
            sleep((int) config('subandl.live_retry_delay_seconds', 2));
        }
    }

    private function appendFile(string $from, string $to): bool
    {
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'ab');
        $ok = $in !== false && $out !== false && stream_copy_to_stream($in, $out) !== false;
        $in && fclose($in);
        $out && fclose($out);
        @unlink($from);

        return $ok;
    }

    /**
     * Opens the package and merges as much of it as the budget allows,
     * continuing from the cursor the last chunk saved in the job.
     *
     * @return array|null null when more is left for the next chunk
     */
    private function mergePackage(array &$job, float $deadline): ?array
    {
        $zipPath = $job['download']['zip'];
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'Update package is not a valid archive.'];
        }

        try {
            if ($zip->numFiles === 0) {
                return ['ok' => false, 'message' => 'Update package is empty.'];
            }

            $cursor = $job['merge'] ?? [];
            $result = $this->extractZipSafely($zip, base_path(), $cursor, $deadline);
            $job['merge'] = $cursor;
        } finally {
            // Closed every chunk: Windows keeps an open file locked.
            $zip->close();
        }

        if ($result !== null && $result['ok']) {
            @unlink($zipPath);
        }

        return $result;
    }

    private function stagingDir(): string
    {
        return rtrim((string) config('subandl.update.staging_path', storage_path('app/update-staging')), '/\\');
    }

    private function jobPath(): string
    {
        return $this->stagingDir() . DIRECTORY_SEPARATOR . 'job.json';
    }

    private function loadJob(): ?array
    {
        $data = json_decode((string) @file_get_contents($this->jobPath()), true);

        return is_array($data) && ($data['format'] ?? null) === 1 && isset($data['run'], $data['phase']) ? $data : null;
    }

    private function saveJob(array $job): void
    {
        $path = $this->jobPath();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        // Temp file + rename, so a crash mid-write never leaves half a job.
        $temp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, json_encode($job)) === false || ! @rename($temp, $path)) {
            @unlink($temp);
            @file_put_contents($path, json_encode($job));
        }
    }

    private function clearJob(): void
    {
        @unlink($this->jobPath());
    }

    /** Drops packages and partial downloads older than a day (an abandoned version). */
    private function cleanupStaging(string $stagingDir): void
    {
        foreach (glob($stagingDir . DIRECTORY_SEPARATOR . 'update-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }

    private static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $bytes : number_format($bytes, 1)) . ' ' . $units[$i];
    }

    private function mergeSummary(array $extraction): string
    {
        return number_format($extraction['written']) . ' file(s) updated, '
            . number_format($extraction['unchanged']) . ' already up to date';
    }

    /**
     * Normalizes a zip entry / manifest line to a safe relative path, or null when
     * it is protected or tries to escape base_path() (zip-slip).
     */
    private function safeRelativePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path) || preg_match('#^[A-Za-z]:#', $path)) {
            return null;
        }

        foreach ((array) config('subandl.update.protected_paths', []) as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return null;
            }
        }

        return $path;
    }

    /**
     * Merges the package into $destination in two passes. Measured on a 20,000
     * file package, PHP's own overhead is small whatever the method (native
     * extractTo() is no faster than this loop); the time goes into disk work.
     * So the merge does as little of it as possible:
     *
     *  1. Compare (0–15%): an entry whose target already has the same size and
     *     CRC32 is left alone — most of a release is usually unchanged. The
     *     CRC of each file this merge writes is remembered in a manifest with
     *     its size and mtime, so the next update trusts an untouched file
     *     without reading it at all; only files changed since are re-hashed.
     *  2. Write (15–100%, by bytes): only the changed entries, with the fewest
     *     file operations each (see extractEntry()).
     *
     * Both passes stop at $deadline and keep their place in $cursor, so the
     * next chunk carries on where this one stopped. Redoing part of a chunk
     * (a request killed mid-way) is harmless: a written file compares equal.
     *
     * @param array $cursor  compared, changed (zip indexes), changed_bytes, unchanged, written, done_bytes
     * @return array{ok: bool, message?: string, extracted: int, written: int, unchanged: int}|null
     */
    private function extractZipSafely(ZipArchive $zip, string $destination, array &$cursor, float $deadline): ?array
    {
        $destination = rtrim($destination, '/\\');
        $manifest = $this->loadManifest($destination);
        $seen = [];
        $cursor += ['compared' => 0, 'compare_done' => false, 'changed' => [], 'changed_bytes' => 0, 'unchanged' => 0, 'written' => 0, 'done_bytes' => 0];

        // Pass 1: find what actually changed.
        if (! $cursor['compare_done']) {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $relative = $stat === false ? null : $this->safeRelativePath($stat['name']);
                if ($relative !== null) {
                    $entries[] = [$i, $stat, $relative];
                }
            }

            $count = count($entries);
            UpdateProgress::stage('merge', 15 * $cursor['compared'] / max(1, $count), "Comparing {$cursor['compared']} of {$count} files…");
            for ($n = $cursor['compared']; $n < $count; $n++) {
                if (microtime(true) >= $deadline && $n > $cursor['compared']) {
                    $cursor['compared'] = $n;
                    $this->saveManifest($destination, $manifest, $seen);

                    return null;
                }

                [$i, $stat, $relative] = $entries[$n];
                if ($this->isUnchanged($stat, $destination . '/' . $relative, $manifest[$relative] ?? null)) {
                    $cursor['unchanged']++;
                    $seen[$relative] = $stat;
                } else {
                    $cursor['changed'][] = $i;
                    $cursor['changed_bytes'] += (int) $stat['size'];
                }
                UpdateProgress::stage('merge', 15 * ($n + 1) / max(1, $count), 'Comparing ' . ($n + 1) . " of {$count} files · " . count($cursor['changed']) . ' changed');
            }

            $cursor['compared'] = $count;
            $cursor['compare_done'] = true;
        }

        // Pass 2: write only the changed entries.
        $total = count($cursor['changed']);
        $startedAt = $cursor['written'];
        while ($cursor['written'] < $total) {
            if (microtime(true) >= $deadline && $cursor['written'] > $startedAt) {
                $this->saveManifest($destination, $manifest, $seen);

                return null;
            }

            $i = $cursor['changed'][$cursor['written']];
            $stat = $zip->statIndex($i);
            $relative = $stat === false ? null : $this->safeRelativePath($stat['name']);
            if ($relative === null) {
                $this->saveManifest($destination, $manifest, $seen);

                return ['ok' => false, 'message' => 'the package changed while it was being merged', 'extracted' => $cursor['written'], 'written' => $cursor['written'], 'unchanged' => $cursor['unchanged']];
            }

            // Laravel turns warnings into ErrorException; both that and a
            // plain failure end the extraction with the entry named.
            try {
                $error = $this->extractEntry($zip, $i, $destination . '/' . $relative, $stat);
            } catch (\ErrorException $e) {
                $error = $e->getMessage();
            }

            if ($error !== null) {
                $this->saveManifest($destination, $manifest, $seen);

                return [
                    'ok' => false,
                    'message' => "failed to extract '{$stat['name']}' ({$error})",
                    'extracted' => $cursor['written'],
                    'written' => $cursor['written'],
                    'unchanged' => $cursor['unchanged'],
                ];
            }

            $seen[$relative] = $stat;
            $cursor['written']++;
            $cursor['done_bytes'] += (int) $stat['size'];
            UpdateProgress::stage(
                'merge',
                15 + 85 * ($cursor['changed_bytes'] > 0 ? $cursor['done_bytes'] / $cursor['changed_bytes'] : $cursor['written'] / max(1, $total)),
                "Writing {$cursor['written']} of {$total} changed files · {$cursor['unchanged']} unchanged"
            );
        }

        $this->saveManifest($destination, $manifest, $seen);

        return ['ok' => true, 'extracted' => $cursor['written'] + $cursor['unchanged'], 'written' => $cursor['written'], 'unchanged' => $cursor['unchanged']];
    }

    /**
     * @param array{0: int, 1: int, 2: int}|null $known  [size, mtime, crc] the manifest holds for this file
     */
    private function isUnchanged(array $stat, string $target, ?array $known): bool
    {
        if (str_ends_with($stat['name'], '/')) {
            return is_dir($target);
        }

        $size = @filesize($target);
        if ($size === false || $size !== (int) $stat['size']) {
            return false;
        }

        $crc = (int) $stat['crc'] & 0xFFFFFFFF;

        // Untouched since the last merge wrote or checked it: its CRC is known.
        if ($known !== null && $known[0] === $size && $known[1] === @filemtime($target)) {
            return $known[2] === $crc;
        }

        $hash = @hash_file('crc32b', $target);

        return $hash !== false && hexdec($hash) === $crc;
    }

    private function manifestPath(): string
    {
        return rtrim((string) config('subandl.update.staging_path', storage_path('app/update-staging')), '/\\')
            . DIRECTORY_SEPARATOR . 'installed-files.json';
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> relative path => [size, mtime, crc] */
    private function loadManifest(string $destination): array
    {
        $data = json_decode((string) @file_get_contents($this->manifestPath()), true);

        return is_array($data) && ($data['root'] ?? null) === $destination && is_array($data['files'] ?? null)
            ? $data['files']
            : [];
    }

    /** @param array<string, array> $seen  relative path => zip stat, for every file now matching the package */
    private function saveManifest(string $destination, array $manifest, array $seen): void
    {
        clearstatcache();
        foreach ($seen as $relative => $stat) {
            if (str_ends_with($stat['name'], '/')) {
                continue;
            }
            $mtime = @filemtime($destination . '/' . $relative);
            if ($mtime !== false) {
                $manifest[$relative] = [(int) $stat['size'], $mtime, (int) $stat['crc'] & 0xFFFFFFFF];
            }
        }

        $path = $this->manifestPath();
        if (is_dir(dirname($path)) || @mkdir(dirname($path), 0755, true)) {
            @file_put_contents($path, json_encode(['root' => $destination, 'files' => $manifest]));
        }
    }

    /**
     * Writes one entry with as few file operations as each case needs:
     *
     *  - a NEW file is written straight to its place: nothing can be reading it;
     *  - on Linux/macOS an EXISTING file is replaced through a temp file next to
     *    it + rename(), so a request running mid-update never reads half a file,
     *    and only the folder needs to be writable (a file owned by another
     *    account — FTP, a deploy user — is replaced too; extractTo() fails on
     *    those, as it also sets the mtime, which only the owner may);
     *  - on Windows an existing file is overwritten in place: one operation
     *    instead of create + rename, each of which Windows Defender scans, and
     *    no permissions to carry over.
     *
     * Entries up to 8 MB are read in one call; bigger ones are streamed.
     *
     * @return string|null the error, or null on success
     */
    private function extractEntry(ZipArchive $zip, int $index, string $target, array $stat): ?string
    {
        if (str_ends_with($stat['name'], '/')) {
            return is_dir($target) || @mkdir($target, 0755, true) ? null : 'could not create directory';
        }

        $dir = dirname($target);
        if (! isset($this->knownDirs[$dir])) {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return "could not create directory '{$dir}'";
            }
            $this->knownDirs[$dir] = true;
        }

        $size = (int) $stat['size'];
        if ($size <= 8 * 1024 * 1024) {
            $source = $zip->getFromIndex($index);
            if ($source === false || strlen($source) !== $size) {
                return $source === false ? ($zip->getStatusString() ?: 'could not read the entry') : self::CORRUPT;
            }
        } else {
            $source = $zip->getStream($stat['name']);
            if ($source === false) {
                return $zip->getStatusString() ?: 'could not read the entry';
            }
        }

        try {
            $exists = is_file($target);
            if (! $exists || Platform::isWindows()) {
                return $this->writeFile($target, $source, $size);
            }

            $temp = $dir . '/.subandl-' . bin2hex(random_bytes(6)) . '.tmp';
            $error = $this->writeFile($temp, $source, $size);
            if ($error === self::CORRUPT) {
                @unlink($temp);

                return $error;
            }

            if ($error !== null) {
                // The folder itself is not writable: overwrite the file in place.
                @unlink($temp);
                if (is_resource($source)) {
                    fclose($source);
                    $source = $zip->getStream($stat['name']);
                }

                return $this->writeFile($target, $source, $size);
            }

            @chmod($temp, fileperms($target) & 0777);
            if (@rename($temp, $target)) {
                return null;
            }

            // Rename refused (sticky folder, target is a directory…): overwrite in place.
            $ok = @copy($temp, $target);
            @unlink($temp);

            return $ok ? null : (error_get_last()['message'] ?? 'permission denied');
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * @param string|resource $source  the entry's bytes, or a stream of them
     * @return string|null null on success, else the error
     */
    private function writeFile(string $path, mixed $source, int $size): ?string
    {
        if (is_string($source)) {
            return @file_put_contents($path, $source) === $size ? null : (error_get_last()['message'] ?? 'permission denied');
        }

        $output = @fopen($path, 'wb');
        if ($output === false) {
            return error_get_last()['message'] ?? 'permission denied';
        }

        $written = stream_copy_to_stream($source, $output);
        fclose($output);

        return $written === $size ? null : self::CORRUPT;
    }

    /**
     * @return list<string> the relative paths that were deleted
     */
    private function applyRemoveManifest(): array
    {
        $manifestName = (string) config('subandl.update.remove_manifest', '');
        $manifest = $manifestName !== '' ? base_path($manifestName) : null;

        if (! $manifest || ! is_file($manifest)) {
            return [];
        }

        $removed = [];

        foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            $relative = $this->safeRelativePath($line);
            if ($relative === null) {
                continue;
            }

            $target = base_path($relative);
            if (is_file($target) || is_link($target)) {
                @unlink($target) && $removed[] = $relative;
            } elseif (is_dir($target)) {
                \Illuminate\Support\Facades\File::deleteDirectory($target) && $removed[] = $relative;
            }
        }

        @unlink($manifest);

        return $removed;
    }

    private function updateEnvVersion(string $version): void
    {
        $envPath = base_path('.env');
        $key = (string) config('subandl.version_env_key', 'APP_VERSION');

        if ($key === '' || ! is_file($envPath) || ! is_writable($envPath)) {
            return;
        }

        $content = (string) file_get_contents($envPath);
        // Keep the file's own line endings (CRLF when edited on Windows).
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $line = $key . '=' . $version;
        $pattern = '/^' . preg_quote($key, '/') . '=[^\r\n]*/m';

        $content = preg_match($pattern, $content)
            ? preg_replace($pattern, $line, $content)
            : rtrim($content) . $eol . $line . $eol;

        file_put_contents($envPath, $content);
    }

    /**
     * Runs in a fresh process so migrations from the files just extracted — and any
     * changed service providers — are what actually executes, not this process's
     * already-loaded classes. The pending migrations are counted first, so the
     * percentage follows each migration as the subprocess reports it DONE.
     *
     * @param bool $commandsFollow post-update commands still run within this stage (its last 15%)
     */
    private function runMigrations(bool $commandsFollow = false): array
    {
        $span = $commandsFollow ? 85 : 99;

        if (! config('subandl.update.run_migrations', true)) {
            UpdateProgress::stage('migrate', $span, 'Migrations are switched off.');

            return ['ok' => true, 'output' => '', 'summary' => 'Migrations are switched off.'];
        }

        $pending = $this->pendingMigrationCount();
        UpdateProgress::stage('migrate', 1, $pending === 0
            ? 'Checking for new migrations…'
            : ($pending === null ? 'Running migrations…' : "0 of {$pending} migrations"));

        $ran = 0;
        $buffer = '';
        $onOutput = function (string $chunk) use (&$ran, &$buffer, $pending, $span) {
            $buffer .= $chunk;
            $lines = preg_split('/\R/', $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $line);
                // "2026_01_01_000000_name .... 12ms DONE" (Laravel 9+) or "Migrated: name".
                if (preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_\w+/', $line, $m) && preg_match('/\bDONE\b|^\s*Migrated:/', $line)) {
                    $ran++;
                    $total = max($pending ?? 0, $ran);
                    UpdateProgress::stage('migrate', $span * $ran / $total, "{$ran} of {$total} migrations · {$m[0]}");
                }
            }
        };

        $result = $this->runArtisanCommands(['migrate --force'], null, $onOutput);
        if ($result['ok']) {
            UpdateProgress::stage('migrate', $span, $ran ? "{$ran} migration(s) run." : 'No new migrations.');
        }

        return [
            'ok' => $result['ok'],
            'message' => $result['message'] ?? null,
            'output' => $result['output'],
            'summary' => $ran ? "{$ran} migration(s) run." : 'Database already up to date.',
        ];
    }

    /** Migration files on disk not yet in the migrations table; null when unknown. */
    private function pendingMigrationCount(): ?int
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

            return count(array_diff(array_keys($files), $ran));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isBlockedCommand(string $command): bool
    {
        $normalized = strtolower(trim($command));

        foreach ((array) config('subandl.update.blocked_commands', []) as $blocked) {
            if ($normalized === $blocked || str_starts_with($normalized, $blocked . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs artisan commands (e.g. post-update commands supplied by the update server)
     * as `php artisan <command>` subprocesses. Anything matching blocked_commands is
     * refused before it runs.
     *
     * @param callable(int $done, int $total, string $command): void|null $onCommand  called before each command
     * @param callable(string $chunk): void|null $onOutput  receives the subprocess output as it arrives
     */
    private function runArtisanCommands(array $commands, ?callable $onCommand = null, ?callable $onOutput = null): array
    {
        $output = '';
        $commands = array_values(array_filter($commands, fn ($c) => is_string($c) && trim($c) !== ''));

        foreach ($commands as $i => $command) {
            if ($this->isBlockedCommand($command)) {
                return ['ok' => false, 'message' => "Blocked destructive command: {$command}", 'output' => $output];
            }

            if ($onCommand) {
                $onCommand($i, count($commands), $command);
            }

            [$ok, $commandOutput] = $this->runArtisan($command, $onOutput);
            $output .= "\$ php artisan {$command}\n" . $commandOutput . "\n";

            if (! $ok) {
                return [
                    'ok' => false,
                    'message' => "Command failed: {$command} — " . self::failureReason($commandOutput),
                    'output' => $output,
                ];
            }
        }

        return ['ok' => true, 'output' => $output];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function runArtisan(string $command, ?callable $onOutput = null): array
    {
        $binary = CliPhpBinary::resolve();
        $process = Process::fromShellCommandline(
            escapeshellarg($binary) . ' ' . escapeshellarg(base_path('artisan')) . ' ' . $command,
            base_path()
        );
        $process->setTimeout(600);

        try {
            $process->run($onOutput ? fn ($type, $chunk) => $onOutput($chunk) : null);
            $output = $process->getOutput() . $process->getErrorOutput();

            // 126/127: the php binary is missing or not executable on this host
            // (common under LiteSpeed/CyberPanel); Windows says so in words
            // with exit 1/9009; or it is a different PHP version that
            // Composer's platform check rejects — run in-process instead of
            // failing the update over a path lookup.
            $wrongBinary = in_array($process->getExitCode(), [126, 127, 9009], true)
                || str_contains($output, 'Your Composer dependencies require a PHP version')
                || preg_match('/is not recognized as an internal or external command|The system cannot find the (path|file) specified/i', $output);

            if (! $wrongBinary) {
                return [
                    $process->isSuccessful(),
                    $process->isSuccessful() ? $output : $output . "\n[exit code {$process->getExitCode()}]",
                ];
            }

            $prefix = "[{$binary} unusable (exit {$process->getExitCode()}): " . trim($output) . "; ran in-process]\n";
        } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
            // proc_open disabled on the host (not a timeout) — fall back to in-process.
            if ($e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException) {
                return [false, $process->getOutput() . $process->getErrorOutput() . "\nTimed out."];
            }

            $prefix = "[subprocess unavailable: {$e->getMessage()}; ran in-process]\n";
        }

        try {
            $ok = Artisan::call($command) === 0;
            $commandOutput = Artisan::output();
            if ($onOutput) {
                $onOutput($commandOutput . "\n");
            }

            return [$ok, $prefix . $commandOutput];
        } catch (\Throwable $e) {
            return [false, $prefix . get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /**
     * Pulls the line that explains a failed artisan run out of its console
     * output — the failing migration plus the SQL/exception message — so the
     * update UI and history say why, not just "Command failed".
     */
    public static function failureReason(string $output): string
    {
        $text = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output);
        $all = array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), 'strlen'));

        // The runner's own "[exit code N]" / "[... ran in-process]" notes are
        // only the reason when the command printed nothing else.
        $isNote = fn ($line) => (bool) preg_match('/^\[(exit code \d+|.*ran in-process)\]$/', $line);
        $notes = array_values(array_filter($all, $isNote));
        $lines = array_values(array_filter($all, fn ($line) => ! $isNote($line)));

        $migration = null;
        foreach ($lines as $line) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6}_\w+)\s.*\bFAIL\b/', $line, $m)) {
                $migration = $m[1];
            }
        }

        $reason = null;
        foreach ($lines as $line) {
            if (preg_match('/SQLSTATE\[|^[\w\\\\]*(Exception|Error):|^(PHP )?(Fatal|Parse) error|not found|Permission denied|unusable|Timed out/i', $line)
                && ! preg_match('/^(#\d+|at |\d+\s*▕|\+\d+ vendor frames)/u', $line)) {
                $reason = $line;
                break;
            }
        }

        // Laravel's console renderer prints the exception class on its own
        // line and the message on the next one.
        if ($reason === null) {
            foreach ($lines as $i => $line) {
                if (preg_match('/^[A-Z][\w\\\\]+(Exception|Error)$/', $line) && isset($lines[$i + 1])) {
                    $reason = $line . ': ' . $lines[$i + 1];
                    break;
                }
            }
        }

        $reason ??= end($lines) ?: (end($notes) ?: 'no output');
        $reason = mb_strimwidth($reason, 0, 600, '…');

        return $migration ? "{$migration}: {$reason}" : $reason;
    }
}
