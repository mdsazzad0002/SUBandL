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

        // LiteSpeed/CyberPanel: PHP_BINARY is .../lsphpXY/bin/lsphp and the CLI
        // binary sits next to it; cPanel, CloudLinux and Plesk keep one CLI per
        // version under their own prefix; other builds ship an unversioned bin/php.
        [$major, $minor] = [PHP_MAJOR_VERSION, PHP_MINOR_VERSION];
        $candidates = [
            dirname(PHP_BINARY) . '/php',
            "/usr/local/lsws/lsphp{$major}{$minor}/bin/php",
            "/opt/cpanel/ea-php{$major}{$minor}/root/usr/bin/php",
            "/opt/alt/php{$major}{$minor}/usr/bin/php",
            "/opt/plesk/php/{$major}.{$minor}/bin/php",
            rtrim(PHP_BINDIR, '/') . '/php',
        ];
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return $versioned;
    }
}
