<?php

namespace SUBandL\Support;

/**
 * Resolves the CLI `php` executable to use when spawning a detached artisan
 * command from within a web request.
 *
 * Under the `cli` SAPI, PHP_BINARY already points at the right executable.
 * Under `fpm-fcgi` it points at php-fpm itself — running that with an artisan
 * path doesn't execute the script, it just prints php-fpm's usage and exits 0,
 * so a spawn would silently be a no-op. Under Apache's mod_php (XAMPP, WAMP)
 * it is httpd(.exe), and under IIS / nginx on Windows php-cgi.exe.
 *
 * config('subandl.php_binary') (SUBANDL_PHP_BINARY) overrides the lookup.
 */
class CliPhpBinary
{
    private static ?string $resolved = null;

    public static function resolve(): string
    {
        return self::$resolved ??= self::find();
    }

    private static function find(): string
    {
        $configured = trim((string) config('subandl.php_binary', ''));
        if ($configured !== '') {
            return Platform::which($configured) ?? $configured;
        }

        if (in_array(PHP_SAPI, ['cli', 'phpdbg', 'cli-server'], true) && Platform::isFile(PHP_BINARY)) {
            return PHP_BINARY;
        }

        $found = Platform::isWindows() ? self::findOnWindows() : self::findOnUnix();

        return $found ?? (Platform::isWindows() ? 'php' : 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
    }

    private static function findOnUnix(): ?string
    {
        [$major, $minor] = [PHP_MAJOR_VERSION, PHP_MINOR_VERSION];
        $versioned = "php{$major}.{$minor}";

        // Paths that name this exact version can be trusted without running them.
        foreach ([
            "/usr/bin/{$versioned}",
            "/usr/local/bin/{$versioned}",
            rtrim(PHP_BINDIR, '/') . "/{$versioned}",
            // LiteSpeed/CyberPanel, cPanel, CloudLinux and Plesk keep one CLI per
            // PHP version under their own prefix.
            "/usr/local/lsws/lsphp{$major}{$minor}/bin/php",
            "/opt/cpanel/ea-php{$major}{$minor}/root/usr/bin/php",
            "/opt/alt/php{$major}{$minor}/usr/bin/php",
            "/opt/plesk/php/{$major}.{$minor}/bin/php",
            "/opt/remi/php{$major}{$minor}/root/usr/bin/php",
            // macOS Homebrew (Apple silicon / Intel).
            "/opt/homebrew/opt/php@{$major}.{$minor}/bin/php",
            "/usr/local/opt/php@{$major}.{$minor}/bin/php",
        ] as $path) {
            if (Platform::isExecutable($path)) {
                return $path;
            }
        }

        // MAMP names its folders by full version (php8.3.14).
        foreach (Platform::glob("/Applications/MAMP/bin/php/php{$major}.{$minor}.*/bin/php") as $path) {
            if (Platform::isExecutable($path)) {
                return $path;
            }
        }

        // An unversioned `php` is often the host's old system default (e.g. 7.3
        // while the site runs 8.3) — only use one that reports this version.
        foreach (array_unique([
            dirname(PHP_BINARY) . '/php',
            rtrim(PHP_BINDIR, '/') . '/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/homebrew/bin/php',
            '/opt/lampp/bin/php',                        // XAMPP for Linux
            '/Applications/XAMPP/xamppfiles/bin/php',    // XAMPP for macOS
        ]) as $path) {
            if (self::reportsThisVersion($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function findOnWindows(): ?string
    {
        [$major, $minor] = [PHP_MAJOR_VERSION, PHP_MINOR_VERSION];

        // php.exe from the very installation this request runs on: next to
        // php-cgi.exe (IIS / nginx), the parent of extension_dir (XAMPP, WAMP,
        // Laragon all set it absolute) or the folder of the loaded php.ini.
        $own = [];
        if (preg_match('/php-cgi\.exe$/i', PHP_BINARY)) {
            $own[] = dirname(PHP_BINARY);
        }
        $extensionDir = (string) ini_get('extension_dir');
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $extensionDir)) {
            $own[] = dirname(rtrim($extensionDir, '/\\'));
        }
        if ($ini = php_ini_loaded_file()) {
            $own[] = dirname($ini);
        }
        if ($phprc = getenv('PHPRC')) {
            $own[] = @is_dir($phprc) ? $phprc : dirname($phprc);
        }
        $own[] = PHP_BINDIR;

        foreach (array_unique($own) as $dir) {
            $path = rtrim($dir, '/\\') . '\\php.exe';
            if (self::reportsThisVersion($path)) {
                return $path;
            }
        }

        // Well-known stacks, newest matching version first.
        $candidates = [];
        foreach (Platform::windowsDrives() as $drive) {
            $candidates = array_merge(
                $candidates,
                ["{$drive}\\xampp\\php\\php.exe"],
                Platform::glob("{$drive}\\wamp64\\bin\\php\\php{$major}.{$minor}*\\php.exe"),
                Platform::glob("{$drive}\\wamp\\bin\\php\\php{$major}.{$minor}*\\php.exe"),
                Platform::glob("{$drive}\\laragon\\bin\\php\\php-{$major}.{$minor}*\\php.exe"),
                Platform::glob("{$drive}\\Program Files\\PHP\\v{$major}.{$minor}*\\php.exe"),
                [
                    "{$drive}\\php\\php.exe",
                    "{$drive}\\tools\\php{$major}{$minor}\\php.exe",   // Chocolatey
                ],
            );
        }
        if ($onPath = Platform::which('php')) {
            $candidates[] = $onPath;
        }

        foreach (array_unique($candidates) as $path) {
            if (self::reportsThisVersion($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function reportsThisVersion(string $path): bool
    {
        if (! Platform::isExecutable($path) || ! Platform::functionEnabled('exec')) {
            return false;
        }

        $output = [];
        $exit = 1;
        // -n: skip php.ini — only the version is wanted, and a broken
        // extension line in someone's ini must not make a good binary fail.
        @exec(
            escapeshellarg($path) . ' -n -r ' . escapeshellarg('echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') . ' 2>' . Platform::nullDevice(),
            $output,
            $exit
        );

        return $exit === 0 && trim(implode('', $output)) === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }
}
