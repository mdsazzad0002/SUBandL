<?php

namespace SUBandL\Backup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use SUBandL\Events\BackupFinished;
use SUBandL\License\LicenseClient;
use SUBandL\License\ServerHealth;
use SUBandL\Models\BackupHistory;
use SUBandL\Models\LicenseState;

/**
 * Uploads a database backup to the provider using its chunked
 * initialize/chunk/complete flow. Only runs on schedule when the customer has
 * opted in via the subscription page toggle (LicenseState::backup_enabled);
 * a forced run ("Backup Now") ignores the toggle and the schedule.
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

    public function nextDueAt(?LicenseState $state = null): \Illuminate\Support\Carbon
    {
        $state ??= LicenseState::current();

        if (! $state->last_backup_at) {
            return now();
        }

        $due = $state->last_backup_at->copy()->addHours((int) config('subandl.backup_interval_hours', 6));

        return $due->isPast() ? now() : $due;
    }

    public function runBackup(bool $force = false): array
    {
        $state = LicenseState::current();

        if ($this->isRunning($state)) {
            return ['ok' => false, 'running' => true, 'message' => 'A backup is already running.'];
        }

        if (! $force && ! $state->backup_enabled) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Backup disabled by customer.'];
        }

        // Due 6h (configurable) after the previous attempt, success or failure alike,
        // so the daily total stays predictable even when a run keeps failing.
        if (! $force && $state->last_backup_at && now()->lt($nextDueAt = $this->nextDueAt($state))) {
            return ['ok' => true, 'skipped' => true, 'message' => "Not due yet. Next backup at {$nextDueAt->toDateTimeString()}."];
        }

        // No point dumping the whole database just to fail the upload.
        if (! $this->health->isHealthy()) {
            $result = ['ok' => false, 'message' => 'Skipped: backup provider is unreachable.'];
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
            $init = Http::acceptJson()->timeout(30)->post($this->client->url('backup/initialize'), $common + [
                'file_name' => $fileName,
                'total_chunks' => $totalChunks,
            ]);
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
            $complete = Http::acceptJson()->timeout(60)->post($this->client->url('backup/complete'), $common + [
                'backup_id' => $backupId,
            ]);
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
