<?php

namespace SUBandL\Backup;

use SUBandL\Support\Platform;

/**
 * Finds mysqldump / pg_dump. A configured absolute path wins; otherwise the
 * PATH, then where the common stacks install them — XAMPP and WAMP never
 * put their MySQL client on the PATH, so "mysqldump" alone fails there.
 */
class DumpBinary
{
    /** @var array<string, ?string> */
    private static array $cache = [];

    /**
     * @param string $configured config value (name or absolute path)
     * @param list<string> $names tried in order, e.g. ['mysqldump', 'mariadb-dump']
     */
    public static function find(string $configured, array $names, string $kind): ?string
    {
        $key = $kind . '|' . $configured;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $configured = trim($configured);
        if ($configured !== '' && (str_contains($configured, '/') || str_contains($configured, '\\'))) {
            // An explicit path is respected as-is (even if missing: the error then names it).
            return self::$cache[$key] = $configured;
        }

        $names = array_values(array_unique(array_filter([$configured, ...$names])));

        foreach ($names as $name) {
            if ($path = Platform::which($name)) {
                return self::$cache[$key] = $path;
            }
        }

        return self::$cache[$key] = Platform::findIn(array_map(
            fn ($n) => preg_replace('/\.exe$/i', '', $n),
            $names
        ), $kind === 'pgsql' ? self::pgsqlDirs() : self::mysqlDirs());
    }

    /** @return list<string> */
    private static function mysqlDirs(): array
    {
        if (Platform::isWindows()) {
            $dirs = [];
            foreach (Platform::windowsDrives() as $d) {
                array_push(
                    $dirs,
                    "{$d}\\xampp\\mysql\\bin",
                    "{$d}\\wamp64\\bin\\mysql\\*\\bin",
                    "{$d}\\wamp64\\bin\\mariadb\\*\\bin",
                    "{$d}\\wamp\\bin\\mysql\\*\\bin",
                    "{$d}\\wamp\\bin\\mariadb\\*\\bin",
                    "{$d}\\laragon\\bin\\mysql\\*\\bin",
                    "{$d}\\laragon\\bin\\mariadb\\*\\bin",
                    "{$d}\\Program Files\\MySQL\\MySQL Server *\\bin",
                    "{$d}\\Program Files\\MariaDB *\\bin",
                    "{$d}\\Program Files (x86)\\MySQL\\MySQL Server *\\bin",
                );
            }

            return $dirs;
        }

        return [
            '/usr/bin',
            '/usr/local/bin',
            '/usr/local/mysql/bin',
            '/opt/lampp/bin',                              // XAMPP for Linux
            '/opt/homebrew/bin',
            '/opt/homebrew/opt/mysql-client/bin',
            '/opt/homebrew/opt/mariadb/bin',
            '/usr/local/opt/mysql-client/bin',
            '/Applications/MAMP/Library/bin',
            '/Applications/MAMP/Library/bin/mysql*/bin',
            '/Applications/XAMPP/xamppfiles/bin',
            '/Applications/DBngin.app/Contents/Resources/mysql/*/bin',
        ];
    }

    /** @return list<string> */
    private static function pgsqlDirs(): array
    {
        if (Platform::isWindows()) {
            $dirs = [];
            foreach (Platform::windowsDrives() as $d) {
                array_push(
                    $dirs,
                    "{$d}\\Program Files\\PostgreSQL\\*\\bin",
                    "{$d}\\laragon\\bin\\postgresql\\*\\bin",
                );
            }

            return $dirs;
        }

        return [
            '/usr/bin',
            '/usr/local/bin',
            '/usr/lib/postgresql/*/bin',
            '/usr/pgsql-*/bin',
            '/opt/homebrew/bin',
            '/opt/homebrew/opt/libpq/bin',
            '/usr/local/opt/libpq/bin',
            '/Applications/Postgres.app/Contents/Versions/latest/bin',
        ];
    }
}
