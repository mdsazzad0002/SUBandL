<?php

namespace SUBandL\Support;

/**
 * Resolves the CLI `php` executable to use when spawning a detached artisan
 * command from within a web request.
 *
 * Under the `cli` SAPI, PHP_BINARY already points at the right executable.
 * Under `fpm-fcgi` it points at php-fpm itself — running that with an artisan
 * path doesn't execute the script, it just prints php-fpm's usage and exits 0,
 * so a spawn would silently be a no-op.
 */
class CliPhpBinary
{
    public static function resolve(): string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        $versioned = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        foreach (['/usr/bin', '/usr/local/bin', PHP_BINDIR] as $dir) {
            $path = rtrim($dir, '/') . '/' . $versioned;
            if (is_executable($path)) {
                return $path;
            }
        }

        // LiteSpeed/CyberPanel, cPanel, CloudLinux and Plesk keep one CLI per
        // PHP version under their own prefix.
        [$major, $minor] = [PHP_MAJOR_VERSION, PHP_MINOR_VERSION];
        foreach ([
            "/usr/local/lsws/lsphp{$major}{$minor}/bin/php",
            "/opt/cpanel/ea-php{$major}{$minor}/root/usr/bin/php",
            "/opt/alt/php{$major}{$minor}/usr/bin/php",
            "/opt/plesk/php/{$major}.{$minor}/bin/php",
        ] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // An unversioned `php` is often the host's old system default (e.g. 7.3
        // while the site runs 8.3) — only use one that reports this version.
        foreach ([dirname(PHP_BINARY) . '/php', rtrim(PHP_BINDIR, '/') . '/php', '/usr/bin/php', '/usr/local/bin/php'] as $path) {
            if (self::reportsThisVersion($path)) {
                return $path;
            }
        }

        return $versioned;
    }

    private static function reportsThisVersion(string $path): bool
    {
        if (! is_executable($path) || ! function_exists('exec')) {
            return false;
        }

        $output = [];
        @exec(escapeshellarg($path) . ' -r ' . escapeshellarg('echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') . ' 2>/dev/null', $output, $exit);

        return $exit === 0 && trim(implode('', $output)) === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }
}
