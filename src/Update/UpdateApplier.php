<?php

namespace SUBandL\Update;

use Illuminate\Support\Facades\Artisan;
use SUBandL\Backup\BackupService;
use SUBandL\Events\UpdateFinished;
use SUBandL\Models\ActivityLog;
use SUBandL\Models\LicenseState;
use SUBandL\Models\UpdateHistory;
use SUBandL\Support\BackgroundArtisan;
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

    /** Where this package itself lives in a release's vendor/. */
    private const SELF_DIR = 'vendor/subandl/subandl/';

    /** config('subandl.update.protected_paths'), read once per process. @var list<string>|null */
    private ?array $protectedPaths = null;

    /** Extra listener for download progress (the background prefetch's status file). @var callable|null */
    private $onDownloadProgress = null;

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

        // The flag is on but no update job exists any more (its process died
        // after the job ended, or an older version left it): nothing is
        // running, so don't make everyone wait out stale_minutes.
        if ($this->loadJob() === null && $state->update_started_at && $state->update_started_at->lt(now()->subMinute())) {
            LicenseState::query()->whereKey($state->getKey())->where('update_running', true)->update(['update_running' => false]);
            $state->forceFill(['update_running' => false])->save();

            return false;
        }

        // A job nobody drives any more (the tab was closed, or the page reloaded
        // mid-update): no chunk for JOB_IDLE_SECONDS and no request holding the
        // update lock. Clear the flag so the Update button is back — clicking it
        // carries on safely (finished files compare equal, migrations resume).
        $job = $this->loadJob();
        if ($job !== null && (int) ($job['touched_at'] ?? 0) < time() - self::JOB_IDLE_SECONDS && $this->updateLockFree()) {
            LicenseState::query()->whereKey($state->getKey())->where('update_running', true)->update([
                'update_running' => false,
                'last_update_status' => 'failed',
                'last_update_message' => 'The update stopped before it finished (the page was closed or reloaded). Click Update to carry on.',
            ]);
            $state->forceFill(['update_running' => false])->save();
            $this->clearJob();

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

    /**
     * No process (chunk, CLI, scheduler) is working on the update job right
     * now. A file lock, not the cache lock: a process killed mid-chunk (FPM
     * timeout, server restart) releases it at once, where a cache lock would
     * stay "held" for its whole TTL.
     */
    private function updateLockFree(): bool
    {
        $lock = $this->fileLock('update.lock');
        if ($lock === null) {
            return false;
        }
        $this->releaseLock($lock);

        return true;
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

        // Held while this process works on the job; the OS drops it the moment
        // the process dies, so a killed request never looks "busy" for long.
        $working = $this->fileLock('update.lock');
        if ($working === null) {
            return ['finished' => false]; // another request is on this job right now
        }

        try {
            return $this->stepLocked($run, $job, $budgetSeconds);
        } finally {
            $this->releaseLock($working);
        }
    }

    private function stepLocked(string $run, array $job, ?float $budgetSeconds): array
    {
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

        // Everything is recorded BEFORE .env changes: `php artisan serve` restarts
        // the moment .env is modified, killing this request — the outcome must
        // already be saved by then (it used to be lost, leaving the update
        // "running" for 30 minutes while the browser retried the last chunk,
        // whose .env write restarted the server again each time).
        $result = $this->finish($job, $result);
        $this->saveLastRun($run, $result);
        $this->clearJob();

        if (($result['ok'] ?? false) && ! empty($job['info']['latest_version'])) {
            $this->updateEnvVersion((string) $job['info']['latest_version']);
        }

        return ['finished' => true, 'result' => $result];
    }

    /**
     * The outcome of $run when that job has already finished — for a browser
     * whose last chunk request was cut off (the server restarted on the .env
     * change) and asks again.
     */
    public function finishedResult(string $run): ?array
    {
        $last = json_decode((string) @file_get_contents($this->lastRunPath()), true);

        return $run !== '' && is_array($last) && ($last['run'] ?? null) === $run && is_array($last['result'] ?? null)
            ? $last['result']
            : null;
    }

    private function saveLastRun(string $run, array $result): void
    {
        unset($result['migration_output'], $result['commands_output']);
        @file_put_contents($this->lastRunPath(), json_encode(['run' => $run, 'result' => $result, 'at' => time()]));
    }

    private function lastRunPath(): string
    {
        return $this->stagingDir() . DIRECTORY_SEPARATOR . 'last-run.json';
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

                    // The backup runs in the background (dump, then upload) while the
                    // package downloads and merges; only the migrations wait, and only
                    // for the dump. Not forced — it backs up when one is due, as before.
                    $job['backup'] ??= $this->startBackgroundBackup($job['run']) ? 'background' : 'inline';

                    if ($job['backup'] === 'background') {
                        UpdateProgress::stage('backup', 5, 'Saving the database in the background…');
                    } else {
                        $backup = $this->preUpdateBackup($job, $deadline);
                        if ($backup === null) {
                            return null;
                        }
                        $failed = $this->recordInlineBackup($job, $backup);
                        if ($failed) {
                            return $failed;
                        }
                    }

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
                    // The database must be saved before it changes.
                    if (($job['backup'] ?? null) === 'background') {
                        $gate = $this->awaitBackgroundBackup($job['run'], $deadline);
                        if ($gate === null) {
                            return null;
                        }
                        if ($gate['fallback'] ?? false) {
                            // The background process never got going: back up right here.
                            $job['backup'] = 'inline';
                            $backup = $this->preUpdateBackup($job, $deadline);
                            if ($backup === null) {
                                return null;
                            }
                            if ($failed = $this->recordInlineBackup($job, $backup)) {
                                return $failed;
                            }
                        } elseif (! $gate['ok']) {
                            return ['ok' => false, 'extracted' => true, 'message' => $gate['message'], 'backup_path' => null];
                        }
                        $job['backup'] = 'done';
                    }

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
                    // .env (the new version number) is written by step(), last of all.
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
        }
        if (! empty($download['zip']) && ($ok || ! ($download['verified'] ?? false))) {
            @unlink($download['zip']);
            @unlink($download['zip'] . '.json');
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
     * Starts the pre-update backup as a detached process (see
     * BackupService::runBeforeUpdate()); false when that is switched off or
     * no process can be spawned — the backup then runs inline as before.
     */
    private function startBackgroundBackup(string $run): bool
    {
        if (! config('subandl.update.background_backup', true)) {
            return false;
        }

        $this->backupService->writeGate($run, 'starting');

        return BackgroundArtisan::dispatch('subandl:backup', ['--before-update=' . $run]);
    }

    /**
     * Waits (within the chunk's budget) until the background backup has saved
     * the database. Its upload is not waited for.
     *
     * @return array{ok: bool, message?: string, fallback?: bool}|null null while still saving
     */
    private function awaitBackgroundBackup(string $run, float $deadline): ?array
    {
        while (true) {
            $gate = $this->backupService->gate($run) ?? ['state' => 'starting', 'at' => 0];
            $state = (string) ($gate['state'] ?? 'starting');
            $age = time() - (int) ($gate['at'] ?? 0);

            if ($state === 'dumped') {
                UpdateProgress::done('backup', $gate['message'] ?? 'Database saved; uploading in the background.');

                return ['ok' => true];
            }
            if ($state === 'skipped') {
                UpdateProgress::skip('backup', $gate['message'] ?? 'Not due.');

                return ['ok' => true];
            }
            if ($state === 'failed') {
                UpdateProgress::fail('backup', $gate['message'] ?? 'Backup failed.');

                return ['ok' => false, 'message' => 'Update files applied, but the migrations were not run: the pre-update backup failed. ' . ($gate['message'] ?? '')];
            }

            // Never picked up (no usable PHP CLI), or its process died before
            // claiming the backup: do it in this request instead.
            if (($state === 'starting' && $age > 60) || (in_array($state, ['dumping', 'waiting'], true) && $age > 60 && ! $this->backupService->isRunning())) {
                return ['ok' => false, 'fallback' => true];
            }

            UpdateProgress::stage('backup', 50, $state === 'waiting'
                ? ($gate['message'] ?? 'Waiting for the backup already running…')
                : 'Saving the database in the background… migrations start right after.');

            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(500000);
        }
    }

    /** Reports an inline backup's outcome; the update's failure result when it failed. */
    private function recordInlineBackup(array &$job, array $backup): ?array
    {
        if (! ($backup['ok'] ?? false)) {
            UpdateProgress::fail('backup', $backup['message'] ?? 'Backup failed.');

            return ['ok' => false, 'message' => 'Aborting update: pre-update backup failed. ' . ($backup['message'] ?? '')];
        }

        $job['backup_id'] = $backup['backup_id'] ?? null;
        ($backup['skipped'] ?? false)
            ? UpdateProgress::skip('backup', $backup['message'] ?? 'Not due.')
            : UpdateProgress::done('backup', 'Backup uploaded.');

        return null;
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
     * (see download()) and checks it against the hash built on the way in.
     *
     * @return array{ok: bool, message?: string}|null null when more is left for the next chunk
     */
    private function fetchPackage(array &$job, float $deadline): ?array
    {
        $info = $job['info'];

        while (! isset($job['download'])) {
            $this->cleanupStaging($this->stagingDir());

            // Downloaded in the background already (see prefetch()): nothing to wait for.
            if ($zip = $this->readyPackage($info, repair: true)) {
                $job['download'] = ['zip' => $zip, 'verified' => true];
                UpdateProgress::done('download', 'Downloaded in the background earlier (' . self::bytes(filesize($zip)) . ').');

                return ['ok' => true];
            }

            // The background download is still going: follow it rather than start a second one.
            if ($this->prefetchRunning()) {
                if (! $this->followPrefetch($deadline)) {
                    return null; // still going; the next chunk looks again
                }

                continue; // it finished (ready) or stopped (download here, from where it got to)
            }

            $job['download'] = $this->newDownload($info);
            UpdateProgress::stage('download', 0, $job['download']['offset'] > 0
                ? 'Resuming at ' . self::bytes($job['download']['offset']) . '…'
                : 'Connecting…');
        }

        $d = &$job['download'];
        if (! isset($d['part'])) {
            return ['ok' => true]; // reused package
        }

        // Only one process downloads a package at a time (this, or the prefetch).
        $lock = $this->downloadLock();
        if ($lock === null) {
            unset($job['download']); // a prefetch got in first: follow it next time

            return $deadline === INF ? $this->fetchPackage($job, $deadline) : null;
        }

        try {
            $received = $this->download($d, $deadline);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'message' => 'Update package download failed: ' . $e->getMessage()];
        } finally {
            $this->releaseLock($lock);
        }

        if ($received !== true) {
            return $received; // null (more to come) or the failure
        }

        $done = $this->completeDownload($d, (string) ($info['latest_version'] ?? ''));
        if ($done['ok']) {
            UpdateProgress::done('download', 'Downloaded ' . self::bytes(filesize($d['zip'])) . '.');
        }

        return $done;
    }

    /** A fresh download of the package — or the rest of one a prefetch or an earlier attempt left. */
    private function newDownload(array $info): array
    {
        $zip = $this->packagePath($info);
        $part = $zip . '.part';
        clearstatcache();

        return [
            'url' => (string) $info['download_url'],
            'zip' => $zip,
            'part' => $part,
            'expected' => $this->expectedChecksum($info),
            'offset' => is_file($part) ? (int) filesize($part) : 0,
            'total' => (int) ($info['size'] ?? $info['file_size'] ?? 0),
            'verified' => false,
            'hash' => null,
        ];
    }

    /** Checks the finished .part file against the checksum, moves it into place and records it as ready. */
    private function completeDownload(array &$d, string $version): array
    {
        // Hashed while it downloaded; only a resumed download without that state reads the file again.
        $context = isset($d['hash']) ? @unserialize(base64_decode($d['hash']), ['allowed_classes' => [\HashContext::class]]) : null;
        if (! $context instanceof \HashContext) {
            UpdateProgress::stage('download', 97, 'Verifying checksum…');
        }
        $actual = $context instanceof \HashContext ? hash_final($context) : hash_file('sha256', $d['part']);
        unset($d['hash']);

        if ($d['expected'] && ! hash_equals($d['expected'], $actual)) {
            @unlink($d['part']);

            return ['ok' => false, 'message' => 'Update package checksum mismatch — download is corrupt or was tampered with.'];
        }

        @unlink($d['zip']);
        if (! @rename($d['part'], $d['zip'])) {
            return ['ok' => false, 'message' => "Could not move the downloaded package into '{$this->stagingDir()}'."];
        }

        $this->writePackageMeta($d['zip'], $version, $actual);
        $d['verified'] = true;
        unset($d['part']);

        return ['ok' => true];
    }

    /**
     * Streams the package into the .part file. In chunked mode it is ONE
     * request per chunk — `Range: bytes=<offset>-`, cut off when the budget
     * runs out — rather than one request per slice, so a slow link to the
     * provider pays its connection setup once per chunk. Whatever arrived
     * is kept (and hashed), so the next chunk, or a retry after the
     * connection dropped, carries on from the byte where it stopped. A
     * server that ignores Range sends the whole file, which restarts at 0.
     *
     * @return bool|array|null true when complete, null when the budget ran out, else the failure
     *
     * @throws \Illuminate\Http\Client\ConnectionException after live_attempts failures without progress
     */
    private function download(array &$d, float $deadline): bool|array|null
    {
        $attempts = max(2, (int) config('subandl.live_attempts', 2));
        $failures = 0;
        // Always hashed on the way in: the checksum to compare, or (without one) the
        // fingerprint the ready-package record keeps.
        $hash = isset($d['hash']) ? @unserialize(base64_decode($d['hash']), ['allowed_classes' => [\HashContext::class]]) : null;
        if (! $hash instanceof \HashContext) {
            $hash = (int) $d['offset'] === 0 ? hash_init('sha256') : null; // null: hash_file() at the end
        }

        while (true) {
            $from = (int) $d['offset'];
            $sink = new PackageSink($d['part'], $from, $hash, $deadline);
            $answer = null;

            $options = [
                'sink' => $sink,
                // Status and range are known before the body: only the right bytes are kept.
                'on_headers' => function ($response) use ($sink, $from, &$answer) {
                    $status = $response->getStatusCode();
                    if ($status === 206 && preg_match('#bytes\s+(\d+)-\d+/(\d+|\*)#i', $response->getHeaderLine('Content-Range'), $m) && (int) $m[1] === $from) {
                        $answer = ['partial', $m[2] === '*' ? 0 : (int) $m[2]];
                        $sink->accept();
                    } elseif ($status === 200) {
                        $answer = ['whole', (int) $response->getHeaderLine('Content-Length')];
                        $sink->restart();
                    } else {
                        $answer = [$status === 206 ? 'bad-range' : 'status', $status];
                    }
                },
                'progress' => function ($total, $received) use (&$d, $sink, &$answer) {
                    $got = (($answer[0] ?? null) === 'whole' ? 0 : (int) $d['offset']) + $sink->written;
                    $all = ($answer[0] ?? null) === 'whole' ? ($answer[1] ?: (int) $total) : (($answer[1] ?? 0) ?: (int) $d['total']);
                    UpdateProgress::stage(
                        'download',
                        $all > 0 ? 95 * $got / $all : 0,
                        $all > 0 ? self::bytes($got) . ' of ' . self::bytes($all) : self::bytes($got) . ' received'
                    );
                    if ($this->onDownloadProgress) {
                        ($this->onDownloadProgress)($got, $all);
                    }
                },
            ];

            $error = null;
            try {
                HttpClient::request()->timeout((int) config('subandl.update.download_timeout', 300))
                    ->withHeaders($from > 0 || $deadline !== INF ? ['Range' => "bytes={$from}-"] : [])
                    ->withOptions($options)
                    ->get($d['url']);
            } catch (\Throwable $e) {
                $error = $sink->budgetSpent ? null : $e;
            } finally {
                $sink->close();
            }
            $budgetSpent = $sink->budgetSpent;

            [$kind, $value] = $answer ?? [null, null];
            if ($kind === 'whole') {
                $d['offset'] = 0;
                $d['total'] = $value;
            } elseif ($kind === 'partial' && $value > 0) {
                $d['total'] = $value;
            }
            $d['offset'] = (int) $d['offset'] + $sink->written;
            $hash = $sink->hash();
            $d['hash'] = $hash ? base64_encode(serialize($hash)) : null;

            if ($sink->diskError) {
                return ['ok' => false, 'message' => "Could not write the download into '{$this->stagingDir()}' (disk full or no permission)."];
            }
            if ($kind === 'status' && $value === 416 && $from > 0) {
                return true; // asked past the end: everything is already here
            }
            if ($kind === 'bad-range') {
                return ['ok' => false, 'message' => 'Update package download failed (the server sent an unexpected byte range).'];
            }
            if ($kind === 'status' && ($value < 500 || ++$failures >= $attempts)) {
                return ['ok' => false, 'message' => "Update package download failed (HTTP {$value})."];
            }

            $complete = $error === null && ! $budgetSpent && $kind !== 'status'
                && ((int) $d['total'] <= 0 || (int) $d['offset'] >= (int) $d['total']);
            if ($complete) {
                return true;
            }
            if ($budgetSpent) {
                return null;
            }

            // Connection dropped, timed out or a 5xx: bytes that arrived count as
            // progress; giving up only after live_attempts tries that got nothing.
            if ($sink->written === 0 && $kind !== 'status' && ++$failures >= $attempts) {
                ActivityLog::record('update', false, "Package download failed after {$failures} attempts: " . ($error ? $error->getMessage() : 'connection closed early'));
                if ($error instanceof \Illuminate\Http\Client\ConnectionException) {
                    throw $error;
                }

                return ['ok' => false, 'message' => 'Update package download failed: ' . ($error ? $error->getMessage() : 'the connection closed early.')];
            }

            $reason = $kind === 'status' ? "HTTP {$value}" : ($error ? $error->getMessage() : 'connection closed early');
            ActivityLog::record('update', false, "Package download interrupted at " . self::bytes($d['offset']) . " ({$reason}); continuing.");
            UpdateProgress::stage('download', 0, "Interrupted ({$reason}); continuing…");
            if ($deadline !== INF && microtime(true) >= $deadline) {
                return null;
            }
            // Bytes got through: the link works, so carry on at once.
            if ($sink->written === 0) {
                sleep((int) config('subandl.live_retry_delay_seconds', 2));
            }
        }
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
            @unlink($zipPath . '.json');
        }

        return $result;
    }

    /* ------------------------------------------------- background download */

    /**
     * Starts downloading the package of a version the provider just announced,
     * in a detached process (update.prefetch, off by default), so the update's
     * download stage is instant.
     * Called after every update check; does nothing when the package is
     * already here or on its way, while an update runs, or for a locked
     * version. Never throws: a check must not fail over this.
     */
    public function schedulePrefetch(array $check): void
    {
        try {
            if (! config('subandl.update.prefetch', false)
                || ! ($check['ok'] ?? false) || ! ($check['update_available'] ?? false)
                || ($check['locked'] ?? false) || empty($check['download_url'])
                || ! LicenseState::current()->updatesIncluded()
                || $this->activeJob() || $this->prefetchRunning()
                || $this->packageStatus((string) ($check['latest_version'] ?? ''))['state'] === 'ready') {
                return;
            }

            unset($check['raw']);
            $dir = $this->stagingDir();
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return;
            }
            @file_put_contents($this->prefetchRequestPath(), json_encode(['check' => $check, 'at' => time()]));

            if (! BackgroundArtisan::dispatch('subandl:update-prefetch')
                && (Platform::functionEnabled('fastcgi_finish_request') || Platform::functionEnabled('litespeed_finish_request'))) {
                // No way to spawn a process: download once this response has been sent.
                app()->terminating(function () {
                    Platform::allowLongRunning();
                    $this->prefetch();
                });
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The background download of the next version's package
     * (subandl:update-prefetch, when update.prefetch is on): resumes a partial
     * download, verifies the checksum and records the package as ready, so
     * the update's download stage is instant. Progress goes to prefetch.json.
     *
     * @param array|null $check  the provider's update answer; null: the one schedulePrefetch() saved
     */
    public function prefetch(?array $check = null): array
    {
        if ($check === null) {
            $saved = json_decode((string) @file_get_contents($this->prefetchRequestPath()), true);
            $check = is_array($saved['check'] ?? null) ? $saved['check'] : null;
        }
        if (! $check || empty($check['download_url'])) {
            return ['ok' => false, 'message' => 'No update to download.'];
        }

        $version = (string) ($check['latest_version'] ?? '');
        if ($this->activeJob()) {
            return ['ok' => true, 'skipped' => true, 'message' => 'An update is being installed; it prepares the package itself.'];
        }

        $download = $this->prefetchDownload($check, $version);
        if (! ($download['ok'] ?? false) || ($download['skipped'] ?? false)) {
            return $download;
        }

        $size = (int) $download['size'];
        $this->writePrefetchStatus($version, 'ready', $size, $size);
        ActivityLog::record('update', true, "v{$version} downloaded in the background (" . self::bytes($size) . '); ready to install.');

        return ['ok' => true, 'message' => "v{$version} downloaded and ready to install."];
    }

    /**
     * Step 1 of prefetch(): the package itself.
     *
     * @return array{ok: bool, message?: string, skipped?: bool, size?: int}
     */
    private function prefetchDownload(array $check, string $version): array
    {
        if ($zip = $this->readyPackage($check, repair: true)) {
            return ['ok' => true, 'size' => (int) filesize($zip)];
        }

        $lock = $this->downloadLock();
        if ($lock === null) {
            return ['ok' => true, 'skipped' => true, 'message' => 'The package is already being downloaded.'];
        }

        try {
            $dir = $this->stagingDir();
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return ['ok' => false, 'message' => "Could not create the update staging folder '{$dir}'."];
            }
            $this->cleanupStaging($dir);

            $d = $this->newDownload($check);
            $this->writePrefetchStatus($version, 'downloading', $d['offset'], $d['total']);
            $writtenAt = 0.0;
            $this->onDownloadProgress = function (int $got, int $all) use ($version, &$writtenAt) {
                if (microtime(true) - $writtenAt >= 1.0) {
                    $writtenAt = microtime(true);
                    $this->writePrefetchStatus($version, 'downloading', $got, $all);
                }
            };

            try {
                $received = $this->download($d, INF);
                $result = $received === true ? $this->completeDownload($d, $version) : $received;
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => 'Update package download failed: ' . $e->getMessage()];
            }

            if ($result['ok'] ?? false) {
                return ['ok' => true, 'size' => (int) filesize($d['zip'])];
            }

            $this->writePrefetchStatus($version, 'failed', (int) ($d['offset'] ?? 0), (int) ($d['total'] ?? 0), $result['message'] ?? null);
            ActivityLog::record('update', false, "Background download of v{$version} failed: " . ($result['message'] ?? 'unknown error') . ' It is downloaded during the update instead.');

            return ['ok' => false, 'message' => $result['message'] ?? 'Download failed.'];
        } finally {
            $this->onDownloadProgress = null;
            $this->releaseLock($lock);
        }
    }

    /**
     * What the widget shows about the next version's package: downloading →
     * ready (failed: the background download did not work and happens during
     * the update instead; null: not downloaded ahead).
     *
     * @return array{state: ?string, received: int, total: int, message: ?string}
     */
    public function packageStatus(?string $version): array
    {
        $status = ['state' => null, 'received' => 0, 'total' => 0, 'message' => null];
        if (! $version) {
            return $status;
        }

        $saved = json_decode((string) @file_get_contents($this->prefetchStatusPath()), true);
        $saved = is_array($saved) && ($saved['version'] ?? null) === $version ? $saved : null;

        $tag = preg_replace('/[^0-9A-Za-z._-]/', '', $version);
        foreach (glob($this->stagingDir() . DIRECTORY_SEPARATOR . "update-{$tag}-*.zip") ?: [] as $zip) {
            $meta = $this->packageMeta($zip);
            if ($meta === null || ($meta['version'] ?? null) !== $version) {
                continue;
            }

            return [
                'state' => 'ready',
                'received' => $meta['size'],
                'total' => $meta['size'],
                'message' => $saved['message'] ?? null,
            ];
        }

        if ($saved !== null) {
            $status = [
                'state' => $this->prefetchRunning() ? 'downloading' : (($saved['state'] ?? null) === 'failed' ? 'failed' : null),
                'received' => (int) ($saved['received'] ?? 0),
                'total' => (int) ($saved['total'] ?? 0),
                'message' => $saved['message'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * The package of $info when it is here, complete and matches the expected
     * checksum — known from the record written when it finished, without
     * reading the file again. $repair: an older package that has no record is
     * checked once by hashing it; one that fails is removed.
     */
    private function readyPackage(array $info, bool $repair = false): ?string
    {
        $zip = $this->packagePath($info);
        clearstatcache();
        if (! is_file($zip)) {
            return null;
        }

        $expected = $this->expectedChecksum($info);
        $meta = $this->packageMeta($zip);
        if ($meta !== null && ($meta['version'] ?? null) === (string) ($info['latest_version'] ?? '')
            && ($expected === null || hash_equals($expected, (string) $meta['sha256']))) {
            return $zip;
        }

        if (! $repair) {
            return null;
        }

        if ($expected) {
            UpdateProgress::stage('download', 50, 'Verifying the package downloaded earlier…');
            if (hash_equals($expected, hash_file('sha256', $zip))) {
                $this->writePackageMeta($zip, (string) ($info['latest_version'] ?? ''), $expected);

                return $zip;
            }
        }

        @unlink($zip);
        @unlink($zip . '.json');

        return null;
    }

    /** The record of a finished package, while the file is still exactly what was recorded. */
    private function packageMeta(string $zip): ?array
    {
        $meta = json_decode((string) @file_get_contents($zip . '.json'), true);
        clearstatcache(false, $zip);

        return is_array($meta) && isset($meta['sha256'], $meta['size'], $meta['mtime'])
            && @filesize($zip) === $meta['size'] && @filemtime($zip) === $meta['mtime']
            ? $meta
            : null;
    }

    private function writePackageMeta(string $zip, string $version, string $sha256): void
    {
        clearstatcache(false, $zip);
        @file_put_contents($zip . '.json', json_encode([
            'version' => $version,
            'sha256' => strtolower($sha256),
            'size' => (int) filesize($zip),
            'mtime' => (int) filemtime($zip),
        ]));
    }

    /**
     * Waits (within the chunk's budget) for the background download,
     * mirroring its progress into the update's download stage.
     *
     * @return bool true once it is no longer running
     */
    private function followPrefetch(float $deadline): bool
    {
        while ($this->prefetchRunning()) {
            $saved = json_decode((string) @file_get_contents($this->prefetchStatusPath()), true) ?: [];
            $got = (int) ($saved['received'] ?? 0);
            $all = (int) ($saved['total'] ?? 0);
            UpdateProgress::stage(
                'download',
                $all > 0 ? 95 * $got / $all : 0,
                'Finishing the background download… ' . ($all > 0 ? self::bytes($got) . ' of ' . self::bytes($all) : self::bytes($got))
            );

            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(500000);
        }

        return true;
    }

    private function packagePath(array $info): string
    {
        $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string) ($info['latest_version'] ?? '')) ?: 'unknown';
        $expected = $this->expectedChecksum($info);

        return $this->stagingDir() . DIRECTORY_SEPARATOR . "update-{$version}-" . ($expected ? substr($expected, 0, 16) : 'nosum') . '.zip';
    }

    private function expectedChecksum(array $info): ?string
    {
        return is_string($info['checksum'] ?? null) && $info['checksum'] !== '' ? strtolower($info['checksum']) : null;
    }

    /** The download lock (flock, so it is released even when the process dies); null when taken. */
    private function downloadLock()
    {
        return $this->fileLock('download.lock');
    }

    /** An flock on staging/$name, released by the OS if the process dies; null when taken. */
    private function fileLock(string $name)
    {
        $dir = $this->stagingDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $handle = @fopen($dir . DIRECTORY_SEPARATOR . $name, 'c');
        if ($handle === false) {
            return null;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Whether some process holds the download lock right now. */
    public function prefetchRunning(): bool
    {
        $path = $this->stagingDir() . DIRECTORY_SEPARATOR . 'download.lock';
        if (! is_file($path)) {
            return false;
        }

        $lock = $this->downloadLock();
        if ($lock === null) {
            return true;
        }
        $this->releaseLock($lock);

        return false;
    }

    private function writePrefetchStatus(string $version, string $state, int $received, int $total, ?string $message = null): void
    {
        @file_put_contents($this->prefetchStatusPath(), json_encode([
            'version' => $version,
            'state' => $state,
            'received' => $received,
            'total' => $total,
            'message' => $message,
            'at' => time(),
        ]));
    }

    private function prefetchStatusPath(): string
    {
        return $this->stagingDir() . DIRECTORY_SEPARATOR . 'prefetch.json';
    }

    private function prefetchRequestPath(): string
    {
        return $this->stagingDir() . DIRECTORY_SEPARATOR . 'prefetch-request.json';
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

    /**
     * Drops packages (and partial downloads) of versions already installed,
     * and anything a week old. A package downloaded in the background for
     * the version still to come is kept until it is installed.
     */
    private function cleanupStaging(string $stagingDir): void
    {
        $current = (string) config('subandl.version', '1.0.0');

        foreach (glob($stagingDir . DIRECTORY_SEPARATOR . 'update-*') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }
            $installed = preg_match('/^update-(.+)-(?:[0-9a-f]{16}|nosum|\d{14})\.zip/', basename($file), $m)
                && version_compare($m[1], $current, '<=');
            if ($installed || filemtime($file) < time() - 7 * 86400) {
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

        if ($path === '' || (str_contains($path, '..') && preg_match('#(^|/)\.\.(/|$)#', $path)) || (isset($path[1]) && $path[1] === ':')) {
            return null;
        }

        $this->protectedPaths ??= array_values(array_filter(array_map('strval', (array) config('subandl.update.protected_paths', [])), 'strlen'));
        foreach ($this->protectedPaths as $prefix) {
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
            $cursor['keep_self'] ??= $this->olderSelfInPackage($zip);
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $relative = $stat === false ? null : $this->safeRelativePath($stat['name']);
                if ($relative !== null && ! ($cursor['keep_self'] && str_starts_with($relative, self::SELF_DIR))) {
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
                $unchanged = $this->isUnchanged($stat, $destination . '/' . $relative, $manifest[$relative] ?? null, $trusted);
                if ($unchanged) {
                    $cursor['unchanged']++;
                    // A file the manifest already vouched for needs no new entry.
                    if (! $trusted) {
                        $seen[$relative] = $stat;
                    }
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
     * Whether the release carries an OLDER copy of this package than the one
     * running. Merging it would swap the updater for one that does not know
     * this job mid-update (the next chunk then runs the old code: the update
     * never finishes and stays "running"), and the old code cannot read the
     * cache as the new one left it. Such a copy is left out — the installed
     * updater stays. A newer copy is merged as usual (it reads this job).
     */
    /** The installed version of this package (Composer's own record), or null. */
    protected function installedSelfVersion(): ?string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion('subandl/subandl');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function olderSelfInPackage(ZipArchive $zip): bool
    {
        $running = $this->installedSelfVersion();

        $json = $zip->getFromName('vendor/composer/installed.json');
        $data = is_string($json) ? json_decode($json, true) : null;
        $packaged = null;
        foreach ((array) ($data['packages'] ?? $data ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'subandl/subandl') {
                $packaged = (string) ($package['version'] ?? '');
            }
        }

        $semver = '/^v?\d+\.\d+\.\d+$/';
        if (! $running || ! $packaged || ! preg_match($semver, $running) || ! preg_match($semver, $packaged)
            || ! version_compare(ltrim($packaged, 'v'), ltrim($running, 'v'), '<')) {
            return false;
        }

        ActivityLog::record('update', true, "The release carries SUBandL {$packaged}, older than the installed {$running}: kept {$running} (vendor/subandl/subandl was not overwritten).");

        return true;
    }

    /**
     * @param array{0: int, 1: int, 2: int}|null $known  [size, mtime, crc] the manifest holds for this file
     * @param bool $trusted  set to whether the manifest alone answered (the file was not read)
     */
    private function isUnchanged(array $stat, string $target, ?array $known, ?bool &$trusted = null): bool
    {
        $trusted = false;
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
            $trusted = $known[2] === $crc;

            return $trusted;
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
        if ($seen === []) {
            return; // nothing new: skip encoding and rewriting the whole file
        }

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

        $updated = preg_match($pattern, $content)
            ? preg_replace($pattern, $line, $content)
            : rtrim($content) . $eol . $line . $eol;

        // Unchanged: leave the file (and its mtime) alone — `php artisan serve`
        // restarts on every .env modification.
        if ($updated !== $content) {
            file_put_contents($envPath, $updated);
        }
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
