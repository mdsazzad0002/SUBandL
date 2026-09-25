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

        return $versioned;
    }
}
