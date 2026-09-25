<?php

namespace SUBandL\Update;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use SUBandL\Backup\BackupService;
use SUBandL\Events\UpdateFinished;
use SUBandL\Models\LicenseState;
use SUBandL\Models\UpdateHistory;
use SUBandL\Support\CliPhpBinary;
use SUBandL\Support\UpdateNotice;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Downloads and applies a single update package (one version step). A backup is
 * taken first when one is due per BackupService's own schedule, and every step
 * is reported, so an update is inspectable rather than a black-box operation.
 *
 * Release zip contract: paths are relative to base_path(). Optionally the zip
 * may contain config('subandl.update.remove_manifest') at its root, listing one
 * relative path per line to delete after extraction.
 */
class UpdateApplier
{
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

    public function downloadAndApply(array $updateInfo): array
    {
        $fromVersion = (string) config('subandl.version', '1.0.0');

        LicenseState::current()->forceFill(['update_running' => true, 'update_started_at' => now()])->save();

        try {
            $result = $this->applyUpdate($updateInfo);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => 'Update failed: ' . $e->getMessage()];
        } finally {
            LicenseState::current()->forceFill(['update_running' => false])->save();
        }

        $ok = (bool) ($result['ok'] ?? false);
        $toVersion = $result['version'] ?? $updateInfo['latest_version'] ?? null;

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

        if ($ok) {
            UpdateNotice::clear();
        }

        event(new UpdateFinished($ok, $fromVersion, $toVersion, $result));

        return $result;
    }

    private function applyUpdate(array $updateInfo): array
    {
        $downloadUrl = $updateInfo['download_url'] ?? null;

        if (! $downloadUrl) {
            return ['ok' => false, 'message' => 'No download URL provided by update server.'];
        }

        // Not forced — only actually backs up when one is due (or none ever ran);
        // otherwise this is a no-op ('skipped' with ok: true).
        $backupResult = $this->backupService->runBackup();
        if (! ($backupResult['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'Aborting update: pre-update backup failed. ' . ($backupResult['message'] ?? '')];
        }
        $backupId = $backupResult['backup_id'] ?? null;

        $stagingDir = rtrim((string) config('subandl.update.staging_path', storage_path('app/update-staging')), '/\\');
        if (! is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $zipPath = $stagingDir . DIRECTORY_SEPARATOR . 'update-' . now()->format('YmdHis') . '.zip';

        try {
            // Streamed to disk rather than held in memory.
            $response = Http::timeout((int) config('subandl.update.download_timeout', 300))
                ->sink($zipPath)
                ->get($downloadUrl);

            if (! $response->successful()) {
                return ['ok' => false, 'message' => 'Update package download failed (HTTP ' . $response->status() . ').'];
            }

            $expected = $updateInfo['checksum'] ?? null;
            if (is_string($expected) && $expected !== '' && ! hash_equals(strtolower($expected), hash_file('sha256', $zipPath))) {
                return ['ok' => false, 'message' => 'Update package checksum mismatch — download is corrupt or was tampered with.'];
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return ['ok' => false, 'message' => 'Update package is not a valid archive.'];
            }

            if ($zip->numFiles === 0) {
                $zip->close();

                return ['ok' => false, 'message' => 'Update package is empty.', 'backup_path' => $backupId];
            }

            $extraction = $this->extractZipSafely($zip, base_path());
            $zip->close();
        } finally {
            @unlink($zipPath);
        }

        if (! $extraction['ok']) {
            return [
                'ok' => false,
                'extracted' => $extraction['extracted'] > 0,
                'message' => 'Update package did not extract cleanly: ' . $extraction['message'],
                'backup_path' => $backupId,
            ];
        }

        $removed = $this->applyRemoveManifest();

        $migrationResult = $this->runMigrations();
        if (! $migrationResult['ok']) {
            return [
                'ok' => false,
                'extracted' => true,
                'message' => 'Update files applied but migrations failed: ' . $migrationResult['message'],
                'backup_path' => $backupId,
            ];
        }

        $commands = array_merge(
            is_array($updateInfo['commands'] ?? null) ? $updateInfo['commands'] : [],
            (array) config('subandl.update.after_update_commands', []),
        );
        $commandsResult = $this->runArtisanCommands($commands);
        if (! $commandsResult['ok']) {
            return [
                'ok' => false,
                'extracted' => true,
                'message' => 'Update files applied but a post-update command failed: ' . $commandsResult['message'],
                'backup_path' => $backupId,
                'migration_output' => $migrationResult['output'],
                'commands_output' => $commandsResult['output'],
            ];
        }

        if (! empty($updateInfo['latest_version'])) {
            $this->updateEnvVersion((string) $updateInfo['latest_version']);
        }

        return [
            'ok' => true,
            'extracted' => true,
            'message' => 'Update v' . ($updateInfo['latest_version'] ?? '') . ' applied successfully.',
            'backup_path' => $backupId,
            'version' => $updateInfo['latest_version'] ?? null,
            'removed' => $removed,
            'migration_output' => $migrationResult['output'],
            'commands_output' => $commandsResult['output'],
        ];
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
     * @return array{ok: bool, message?: string, extracted: int}
     */
    private function extractZipSafely(ZipArchive $zip, string $destination): array
    {
        $extracted = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryPath = $zip->getNameIndex($i);
            if ($entryPath === false || $this->safeRelativePath($entryPath) === null) {
                continue;
            }

            // extractTo() signals failure (permission denied, disk full, CRC mismatch)
            // either as a plain `false` or — since Laravel converts warnings — as an
            // ErrorException; both must be handled.
            $extractError = null;

            try {
                $ok = $zip->extractTo($destination, $entryPath);
            } catch (\ErrorException $e) {
                $ok = false;
                $extractError = $e->getMessage();
            }

            if (! $ok) {
                return [
                    'ok' => false,
                    'message' => "failed to extract '{$entryPath}' (" . ($extractError ?? $zip->getStatusString() ?: 'unknown error') . ')',
                    'extracted' => $extracted,
                ];
            }

            $extracted++;
        }

        return ['ok' => true, 'extracted' => $extracted];
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
        $line = $key . '=' . $version;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        $content = preg_match($pattern, $content)
            ? preg_replace($pattern, $line, $content)
            : rtrim($content) . "\n" . $line . "\n";

        file_put_contents($envPath, $content);
    }

    /**
     * Runs in a fresh process so migrations from the files just extracted — and any
     * changed service providers — are what actually executes, not this process's
     * already-loaded classes.
     */
    private function runMigrations(): array
    {
        if (! config('subandl.update.run_migrations', true)) {
            return ['ok' => true, 'output' => ''];
        }

        $result = $this->runArtisanCommands(['migrate --force']);

        return ['ok' => $result['ok'], 'message' => $result['message'] ?? null, 'output' => $result['output']];
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
     */
    private function runArtisanCommands(array $commands): array
    {
        $output = '';

        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '') {
                continue;
            }

            if ($this->isBlockedCommand($command)) {
                return ['ok' => false, 'message' => "Blocked destructive command: {$command}", 'output' => $output];
            }

            [$ok, $commandOutput] = $this->runArtisan($command);
            $output .= "\$ php artisan {$command}\n" . $commandOutput . "\n";

            if (! $ok) {
                return ['ok' => false, 'message' => "Command failed: {$command}", 'output' => $output];
            }
        }

        return ['ok' => true, 'output' => $output];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function runArtisan(string $command): array
    {
        $process = Process::fromShellCommandline(
            escapeshellarg(CliPhpBinary::resolve()) . ' ' . escapeshellarg(base_path('artisan')) . ' ' . $command,
            base_path()
        );
        $process->setTimeout(600);

        try {
            $process->run();

            return [$process->isSuccessful(), $process->getOutput() . $process->getErrorOutput()];
        } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
            // proc_open disabled on the host (not a timeout) — fall back to in-process.
            if ($e instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException) {
                return [false, $process->getOutput() . $process->getErrorOutput() . "\nTimed out."];
            }
        }

        try {
            return [Artisan::call($command) === 0, Artisan::output()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}
