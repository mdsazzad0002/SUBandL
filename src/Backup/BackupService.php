<?php

namespace SUBandL\Backup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use SUBandL\Events\BackupFinished;
use SUBandL\License\LicenseClient;
use SUBandL\License\ServerHealth;
use SUBandL\Models\ActivityLog;
use SUBandL\Models\BackupHistory;
use SUBandL\Models\LicenseState;

/**
 * Uploads a database backup to the provider using its chunked
 * initialize/chunk/complete flow. Runs every intervalHours() (never less than
 * backup_min_interval_hours, default 8) when the
 * customer has opted in via the subscription page toggle
 * (LicenseState::backup_enabled), and — regardless of that toggle — whenever
 * there has been no successful backup for 24 hours (backup_daily_minimum).
 * A forced run ("Backup Now") ignores the toggle and the schedule.
 *
 * Supports mysql/mariadb (mysqldump), pgsql (pg_dump) and sqlite (file copy).
 */
class BackupService
{
    public function __construct(
        private readonly LicenseClient $client,
        private readonly ServerHealth $health,
    ) {
    }

    /**
     * Whether a backup is currently in flight, per the flag set at the start of
     * runBackup() and cleared in recordResult().
     */
    public function isRunning(?LicenseState $state = null): bool
    {
        $state ??= LicenseState::current();

        if (! $state->backup_running) {
            return false;
        }

        if ($state->backup_started_at && $state->backup_started_at->lt(now()->subMinutes($this->staleMinutes()))) {
            $this->recoverFromStaleRun($state);

            return false;
        }

        return true;
    }

    /**
     * A stuck backup_running flag means the process behind it was killed (host
     * timeout/OOM on a large database) before recordResult(). Record that
     * explicitly and reclaim the orphaned dump. The conditional update makes sure
     * only one of several concurrent isRunning() callers performs the recovery.
     */
    private function recoverFromStaleRun(LicenseState $state): void
    {
        $message = 'Previous backup was interrupted before it could finish and has been reset.';

        $recovered = LicenseState::query()
            ->whereKey($state->getKey())
            ->where('backup_running', true)
            ->update([
                'backup_running' => false,
                'last_backup_at' => now(),
                'last_backup_status' => 'failed',
                'last_backup_message' => $message,
            ]);

        if (! $recovered) {
            return;
        }

        // update() bypasses model events, so the cached row must be dropped by hand.
        $state->forceFill(['backup_running' => false])->save();

        BackupHistory::create(['ok' => false, 'message' => $message]);

        $this->cleanupOrphanedDumps();
    }

    private function cleanupOrphanedDumps(): void
    {
        $cutoff = now()->subMinutes($this->staleMinutes())->timestamp;

        foreach (glob($this->stagingPath() . '/database-*') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Hours between automatic backups: backup_interval_hours, but never less
     * than backup_min_interval_hours (8 by default) — a backup is a full
     * database dump, so it is kept well apart.
     */
    public static function intervalHours(): int
    {
        return max(
            (int) config('subandl.backup_min_interval_hours', 8),
            (int) config('subandl.backup_interval_hours', 8),
            1,
        );
    }

    public function nextDueAt(?LicenseState $state = null): Carbon
    {
        $state ??= LicenseState::current();

        $due = $this->scheduledDueAt($state);

        if ($this->dailyMinimumApplies($state) && (! $state->backup_enabled || $this->dailyDueAt($state)->lt($due))) {
            $due = $this->dailyDueAt($state);
        }

        return $due->isPast() ? now() : $due;
    }

    /**
     * The customer's automatic backups: due backup_interval_hours after the
     * previous attempt, success or failure alike, so the daily total stays
     * predictable even when a run keeps failing.
     */
    private function scheduledDueAt(LicenseState $state): Carbon
    {
        return $state->last_backup_at
            ? $state->last_backup_at->copy()->addHours(self::intervalHours())
            : now();
    }

    /**
     * The daily guarantee: at least one SUCCESSFUL backup every 24 hours, even
     * with the customer's automatic backup switched off. Once overdue, a failed
     * attempt is retried every backup_daily_retry_minutes rather than every
     * scheduler tick.
     */
    private function dailyDueAt(LicenseState $state): Carbon
    {
        $lastSuccess = $this->lastSuccessfulAt();
        $due = $lastSuccess ? $lastSuccess->copy()->addDay() : now();

        if ($state->last_backup_at) {
            $retryAt = $state->last_backup_at->copy()->addMinutes((int) config('subandl.backup_daily_retry_minutes', 60));
            $due = $due->max($retryAt);
        }

        return $due;
    }

    /** Only for a working license — an unlicensed install can't upload anyway. */
    private function dailyMinimumApplies(LicenseState $state): bool
    {
        return (bool) config('subandl.backup_daily_minimum', true) && $state->isUsable();
    }

    public function lastSuccessfulAt(): ?Carbon
    {
        return BackupHistory::query()->where('ok', true)->latest('id')->first(['created_at'])?->created_at;
    }

    /** No successful backup within the last 24 hours. */
    public function isDailyBackupOverdue(): bool
    {
        $lastSuccess = $this->lastSuccessfulAt();

        return ! $lastSuccess || $lastSuccess->lt(now()->subDay());
    }

    public function runBackup(bool $force = false): array
    {
        $state = LicenseState::current();

        if ($this->isRunning($state)) {
            return ['ok' => false, 'running' => true, 'message' => 'A backup is already running.'];
        }

        if (! $state->isUsable()) {
            return ['ok' => false, 'skipped' => true, 'message' => 'A valid license is required for backups.'];
        }

        if (! $force) {
            $scheduledDue = $state->backup_enabled && now()->gte($this->scheduledDueAt($state));
            $dailyDue = $this->dailyMinimumApplies($state) && now()->gte($this->dailyDueAt($state));

            if (! $scheduledDue && ! $dailyDue) {
                if (! $state->backup_enabled && ! $this->dailyMinimumApplies($state)) {
                    return ['ok' => true, 'skipped' => true, 'message' => 'Backup disabled by customer.'];
                }

                return ['ok' => true, 'skipped' => true, 'message' => "Not due yet. Next backup at {$this->nextDueAt($state)->toDateTimeString()}."];
            }
        }

        // No point dumping the whole database just to fail the upload: while
        // the provider is in its back-off window, automatic runs just wait
        // (nothing is recorded, so the retry happens as soon as it ends);
        // otherwise the server checks the provider itself right before
        // dumping. A failed probe starts a 30+ minute back-off.
        $probe = $this->health->gate('backup', respectBackoff: ! $force);
        if (! $probe['ok'] && $probe['attempts'] === 0) {
            // Still inside the back-off window: wait, record nothing.
            return ['ok' => true, 'skipped' => true, 'message' => $probe['message']];
        }
        if (! $probe['ok']) {
            $result = ['ok' => false, 'message' => 'Skipped: ' . $probe['message']];
            $this->recordResult($state, $result);

            return $result;
        }

        $state->forceFill(['backup_running' => true, 'backup_started_at' => now()])->save();

        try {
            $dump = $this->dumpDatabase();
        } catch (\Throwable $e) {
            $dump = ['path' => null, 'message' => $e->getMessage()];
        }

        if (! $dump['path']) {
            $result = ['ok' => false, 'message' => 'Database dump failed. ' . ($dump['message'] ?? '')];
            $this->recordResult($state, $result);

            return $result;
        }

        try {
            $result = $this->upload($dump['path']);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => 'Backup upload failed: ' . $e->getMessage()];
        } finally {
            @unlink($dump['path']);
        }

        $this->recordResult($state, $result);

        return $result;
    }

    private function recordResult(LicenseState $state, array $result): void
    {
        $ok = (bool) ($result['ok'] ?? false);

        $state->last_backup_at = now();
        $state->last_backup_status = $ok ? 'success' : 'failed';
        $state->last_backup_message = $result['message'] ?? null;
        $state->backup_running = false;
        $state->save();

        BackupHistory::create([
            'ok' => $ok,
            'backup_id' => $result['backup_id'] ?? null,
            'message' => $result['message'] ?? ($ok ? 'Backup completed successfully.' : null),
        ]);

        event(new BackupFinished($ok, $result));
    }

    private function upload(string $dumpPath): array
    {
        $chunkSize = (int) config('subandl.backup.chunk_size', 2 * 1024 * 1024);
        $retryAttempts = (int) config('subandl.backup.chunk_retry_attempts', 5);
        $fileName = basename($dumpPath);
        $totalChunks = max(1, (int) ceil(filesize($dumpPath) / $chunkSize));
        $common = $this->client->identityPayload();

        try {
            $init = $this->postTwice('backup/initialize', $common + [
                'file_name' => $fileName,
                'total_chunks' => $totalChunks,
            ], 30);
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'message' => 'Unable to reach backup server: ' . $exception->getMessage()];
        }

        $initJson = $init->json() ?? [];
        if (! $init->successful() || ($initJson['status'] ?? null) !== 'ok') {
            return ['ok' => false, 'message' => $initJson['message'] ?? 'Backup initialize failed (HTTP ' . $init->status() . ').'];
        }

        $backupId = $initJson['backup_id'];
        $alreadyReceived = ($initJson['resumed'] ?? false)
            ? array_flip(array_map('intval', $initJson['received_chunks'] ?? []))
            : [];

        $handle = fopen($dumpPath, 'rb');

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                // The server already has this chunk from an earlier, interrupted
                // attempt at the same backup_id.
                if (isset($alreadyReceived[$index])) {
                    continue;
                }

                fseek($handle, $index * $chunkSize);
                $chunk = fread($handle, $chunkSize);

                // Hundreds of back-to-back chunk requests can trip the provider's rate
                // limit (429) — retry transient failures with backoff.
                $chunkResponse = null;
                $lastMessage = null;
                for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
                    try {
                        $chunkResponse = Http::acceptJson()->timeout(60)
                            ->attach('chunk', $chunk, "{$index}.part")
                            ->post($this->client->url('backup/chunk'), $common + [
                                'backup_id' => $backupId,
                                'chunk_index' => $index,
                                'total_chunks' => $totalChunks,
                            ]);
                    } catch (ConnectionException $exception) {
                        $lastMessage = 'Unable to reach backup server during upload: ' . $exception->getMessage();
                        $chunkResponse = null;
                    }

                    if ($chunkResponse && $chunkResponse->successful()) {
                        break;
                    }

                    if ($chunkResponse) {
                        $lastMessage = 'Chunk ' . $index . ' upload failed (HTTP ' . $chunkResponse->status() . ').';
                        // A 4xx other than 429 means the request itself is wrong.
                        if ($chunkResponse->status() !== 429 && $chunkResponse->status() < 500) {
                            return ['ok' => false, 'message' => $lastMessage];
                        }
                    }

                    if ($attempt < $retryAttempts) {
                        $retryAfter = $chunkResponse ? (int) $chunkResponse->header('Retry-After') : 0;
                        sleep(max($retryAfter, $attempt * 2));
                    }
                }

                if (! $chunkResponse || ! $chunkResponse->successful()) {
                    return ['ok' => false, 'message' => $lastMessage ?? ('Chunk ' . $index . ' upload failed.')];
                }
            }
        } finally {
            fclose($handle);
        }

        try {
            $complete = $this->postTwice('backup/complete', $common + [
                'backup_id' => $backupId,
            ], 60);
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'message' => 'Unable to reach backup server to complete: ' . $exception->getMessage()];
        }

        $completeJson = $complete->json() ?? [];
        if (! $complete->successful() || ($completeJson['status'] ?? null) !== 'ok') {
            return ['ok' => false, 'message' => $completeJson['message'] ?? 'Backup complete step failed (HTTP ' . $complete->status() . ').'];
        }

        return ['ok' => true, 'backup_id' => $backupId, 'message' => 'Backup completed successfully.', 'response' => $completeJson];
    }

    /**
     * A backup step POST, tried at least twice on a connection error or 5xx.
     *
     * @throws ConnectionException when every attempt failed to connect
     */
    private function postTwice(string $endpoint, array $payload, int $timeout): \Illuminate\Http\Client\Response
    {
        $attempts = max(2, (int) config('subandl.live_attempts', 2));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::acceptJson()->timeout($timeout)->post($this->client->url($endpoint), $payload);

                if (! $response->serverError() || $attempt >= $attempts) {
                    return $response;
                }
                $reason = 'HTTP ' . $response->status();
            } catch (ConnectionException $e) {
                if ($attempt >= $attempts) {
                    ActivityLog::record('backup', false, "/{$endpoint} failed after {$attempt} attempts: {$e->getMessage()}");
                    throw $e;
                }
                $reason = $e->getMessage();
            }

            ActivityLog::record('backup', false, "/{$endpoint} attempt {$attempt} failed ({$reason}); retrying.");
            sleep((int) config('subandl.live_retry_delay_seconds', 2));
        }
    }

    /**
     * @return array{path: ?string, message?: string}
     */
    private function dumpDatabase(): array
    {
        $connection = config('subandl.backup.connection') ?: config('database.default');
        $config = config("database.connections.{$connection}", []);
        $driver = $config['driver'] ?? 'mysql';

        $dir = $this->stagingPath();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $base = $dir . DIRECTORY_SEPARATOR . 'database-' . now()->format('Y-m-d-H-i-s');

        return match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($config, "{$base}.sql"),
            'pgsql' => $this->dumpPgsql($config, "{$base}.sql"),
            'sqlite' => $this->dumpSqlite($config, "{$base}.sqlite"),
            default => ['path' => null, 'message' => "Unsupported database driver '{$driver}'."],
        };
    }

    private function dumpMysql(array $config, string $path): array
    {
        $command = [config('subandl.backup.mysqldump_binary', 'mysqldump')];

        if (! empty($config['unix_socket'])) {
            $command[] = '--socket=' . $config['unix_socket'];
        } else {
            $command[] = '--host=' . ($config['host'] ?? '127.0.0.1');
            $command[] = '--port=' . ($config['port'] ?? 3306);
        }

        $command[] = '--user=' . ($config['username'] ?? '');
        array_push($command, ...(array) config('subandl.backup.mysqldump_options', []));
        // Written straight to disk rather than buffered through PHP, so a large
        // database never has to fit in memory.
        $command[] = '--result-file=' . $path;
        $command[] = $config['database'] ?? '';

        $result = Process::timeout(3600)->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->run($command);

        return $this->dumpResult($result, $path);
    }

    private function dumpPgsql(array $config, string $path): array
    {
        $result = Process::timeout(3600)->env(['PGPASSWORD' => (string) ($config['password'] ?? '')])->run([
            config('subandl.backup.pg_dump_binary', 'pg_dump'),
            '--host=' . ($config['host'] ?? '127.0.0.1'),
            '--port=' . ($config['port'] ?? 5432),
            '--username=' . ($config['username'] ?? ''),
            '--no-owner',
            '--file=' . $path,
            $config['database'] ?? '',
        ]);

        return $this->dumpResult($result, $path);
    }

    private function dumpSqlite(array $config, string $path): array
    {
        $database = $config['database'] ?? '';

        if (! is_file($database) || ! @copy($database, $path)) {
            return ['path' => null, 'message' => "Could not copy SQLite database '{$database}'."];
        }

        return ['path' => $path];
    }

    private function dumpResult(\Illuminate\Contracts\Process\ProcessResult $result, string $path): array
    {
        if (! $result->successful() || ! is_file($path) || filesize($path) === 0) {
            @unlink($path);

            return ['path' => null, 'message' => trim($result->errorOutput())];
        }

        return ['path' => $path];
    }

    private function stagingPath(): string
    {
        return rtrim((string) config('subandl.backup.staging_path', storage_path('app/backup-staging')), '/\\');
    }

    private function staleMinutes(): int
    {
        return (int) config('subandl.stale_minutes', 30);
    }
}
