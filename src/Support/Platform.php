<?php

namespace SUBandL\Support;

/**
 * The few OS differences the package cares about — Linux, macOS and Windows
 * (XAMPP, WAMP, Laragon, IIS) — in one place.
 *
 * Every filesystem probe here is silenced: on hosts with open_basedir
 * (cPanel, Plesk, CyberPanel) checking a path outside the allowed folders
 * raises a warning, which Laravel turns into an exception — a missing
 * binary must never take a page down.
 */
class Platform
{
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function isMac(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /** Where shell output goes to be discarded. */
    public static function nullDevice(): string
    {
        return self::isWindows() ? 'NUL' : '/dev/null';
    }

    public static function functionEnabled(string $name): bool
    {
        return function_exists($name)
            && ! in_array($name, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    /**
     * Lifts the time limit for long work run inline (a backup when no
     * background process can be spawned). set_time_limit is disabled on many
     * shared hosts, and calling a disabled function is fatal in PHP 8.
     */
    public static function allowLongRunning(): void
    {
        if (self::functionEnabled('set_time_limit')) {
            @set_time_limit(0);
        }
        if (self::functionEnabled('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    public static function isFile(string $path): bool
    {
        return $path !== '' && @is_file($path);
    }

    public static function isExecutable(string $path): bool
    {
        if (! self::isFile($path)) {
            return false;
        }

        // Windows has no execute bit; an existing .exe/.bat/.cmd is runnable.
        return self::isWindows()
            ? (bool) preg_match('/\.(exe|bat|cmd|com)$/i', $path)
            : @is_executable($path);
    }

    /** glob() that never warns and returns the newest-looking match first. */
    public static function glob(string $pattern): array
    {
        $matches = @glob($pattern) ?: [];
        natsort($matches);

        return array_reverse(array_values($matches));
    }

    /**
     * Drive roots to look for XAMPP / WAMP / Laragon on: the system drive,
     * the app's own drive (D:\xampp is common) and C:.
     *
     * @return list<string> e.g. ['C:', 'D:']
     */
    public static function windowsDrives(): array
    {
        $drives = [strtoupper((string) getenv('SystemDrive')) ?: 'C:', 'C:'];

        if (preg_match('/^([A-Za-z]:)/', base_path(), $m)) {
            array_unshift($drives, strtoupper($m[1]));
        }

        return array_values(array_unique(array_filter($drives)));
    }

    /**
     * A program by name on the PATH (with PATHEXT on Windows), or null.
     */
    public static function which(string $name): ?string
    {
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return self::isExecutable($name) ? $name : null;
        }

        $extensions = [''];
        if (self::isWindows()) {
            $extensions = preg_match('/\.[a-z0-9]+$/i', $name)
                ? ['']
                : array_merge([''], explode(';', strtolower((string) (getenv('PATHEXT') ?: '.EXE;.BAT;.CMD;.COM'))));
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $dir = trim($dir, " \"");
            if ($dir === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
                if (self::isExecutable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * The first runnable file among names × directories (directories may be
     * glob patterns; the newest match is tried first).
     *
     * @param list<string> $names without extension
     * @param list<string> $dirs
     */
    public static function findIn(array $names, array $dirs): ?string
    {
        $suffix = self::isWindows() ? '.exe' : '';

        foreach ($dirs as $pattern) {
            $expanded = str_contains($pattern, '*') ? self::glob($pattern) : [$pattern];
            foreach ($expanded as $dir) {
                foreach ($names as $name) {
                    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $suffix;
                    if (self::isExecutable($path)) {
                        return $path;
                    }
                }
            }
        }

        return null;
    }
}
