<?php

namespace SUBandL\Update;

/**
 * Live progress of the update step in flight — one entry per stage (check,
 * backup, download, merge, migrate), each with its own percentage — so the
 * update modal can show a bar per stage while the run request is still open.
 *
 * Kept in a small JSON file next to the staging folder rather than the cache:
 * post-update commands run cache:clear, and the browser polls it from a
 * second request while the first one works. Writes are throttled, so a
 * 10,000-file merge does not mean 10,000 disk writes.
 */
class UpdateProgress
{
    public const STAGES = [
        'check' => 'Check',
        'backup' => 'Backup',
        'download' => 'Download',
        'merge' => 'Merge files',
        'migrate' => 'Migrate',
    ];

    private static ?array $data = null;

    private static float $flushedAt = 0.0;

    /** @param string|null $run the browser's token for this step, echoed back so it can tell its own snapshot */
    public static function start(string $fromVersion, ?string $toVersion = null, ?string $run = null): void
    {
        $stages = [];
        foreach (self::STAGES as $key => $label) {
            $stages[$key] = ['label' => $label, 'status' => 'pending', 'percent' => 0, 'detail' => null];
        }

        self::$data = [
            'run' => $run,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'current' => null,
            'stages' => $stages,
            'updated_at' => now()->toDateTimeString(),
        ];

        self::flush();
    }

    /** Whether start() ran in this process (the controller starts it before the check). */
    public static function active(): bool
    {
        return self::$data !== null;
    }

    public static function target(?string $toVersion): void
    {
        if (self::$data === null) {
            return;
        }

        self::$data['to_version'] = $toVersion;
        self::flush();
    }

    /** Throttled: written at most every 300ms unless the stage changes state. */
    public static function stage(string $key, float $percent, ?string $detail = null): void
    {
        if (self::$data === null || ! isset(self::$data['stages'][$key])) {
            return;
        }

        $stage = &self::$data['stages'][$key];
        $percent = (int) max(0, min(99, floor($percent)));
        $changed = $stage['status'] !== 'running' || self::$data['current'] !== $key;

        $stage['status'] = 'running';
        $stage['percent'] = max($stage['percent'], $percent);
        if ($detail !== null) {
            $stage['detail'] = $detail;
        }
        self::$data['current'] = $key;

        if ($changed || microtime(true) - self::$flushedAt >= 0.3) {
            self::flush();
        }
    }

    public static function done(string $key, ?string $detail = null): void
    {
        self::finish($key, 'done', $detail);
    }

    public static function skip(string $key, ?string $detail = null): void
    {
        self::finish($key, 'skipped', $detail);
    }

    public static function fail(string $key, ?string $detail = null): void
    {
        self::finish($key, 'failed', $detail);
    }

    /** Marks whichever stage is running as failed (an unexpected exception). */
    public static function failCurrent(?string $detail = null): void
    {
        if (self::$data !== null && self::$data['current']) {
            self::fail(self::$data['current'], $detail);
        }
    }

    public static function get(): ?array
    {
        $path = self::path();
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private static function finish(string $key, string $status, ?string $detail): void
    {
        if (self::$data === null || ! isset(self::$data['stages'][$key])) {
            return;
        }

        $stage = &self::$data['stages'][$key];
        $stage['status'] = $status;
        $stage['percent'] = $status === 'failed' ? $stage['percent'] : 100;
        if ($detail !== null) {
            $stage['detail'] = $detail;
        }
        self::$data['current'] = $key;

        self::flush();
    }

    private static function flush(): void
    {
        self::$flushedAt = microtime(true);
        self::$data['updated_at'] = now()->toDateTimeString();

        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }

        // Temp file + rename, so a poll never reads half a file.
        $temp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, json_encode(self::$data)) === false || ! @rename($temp, $path)) {
            @unlink($temp);
            @file_put_contents($path, json_encode(self::$data));
        }
    }

    private static function path(): string
    {
        $staging = rtrim((string) config('subandl.update.staging_path', storage_path('app/update-staging')), '/\\');

        return $staging . DIRECTORY_SEPARATOR . 'progress.json';
    }
}
