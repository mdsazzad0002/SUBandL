<?php

namespace SUBandL\Installer;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SUBandL\Backup\PhpMysqlDumper;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The database that ships inside an installer package.
 *
 *  - fresh:   every migration (and the seeders) run into a scratch database,
 *             which is then dumped. The project's own database is never touched.
 *  - schema:  the current database's structure, with rows only for the
 *             `schema_data_tables` (by default just `migrations`).
 *  - current: a full copy of the current database, data included.
 */
class DatabaseSnapshot
{
    public const MODES = ['fresh', 'schema', 'current', 'none'];

    private const SCRATCH_CONNECTION = 'subandl_installer';

    /**
     * @return array{ok: bool, path?: string, extension?: string, message?: string, warnings: list<string>}
     */
    public function make(string $mode, string $directory, bool $seed = true, ?string $scratchDatabase = null): array
    {
        $name = (string) config('database.default');
        $config = (array) config("database.connections.{$name}");
        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return ['ok' => false, 'message' => "Database driver '{$driver}' is not supported for installer packages (mysql, mariadb or sqlite).", 'warnings' => []];
        }

        return match ($mode) {
            'fresh' => $driver === 'sqlite'
                ? $this->freshSqlite($config, $directory, $seed)
                : $this->freshMysql($name, $config, $directory, $seed, $scratchDatabase),
            'schema' => $driver === 'sqlite'
                ? ['ok' => false, 'message' => "Mode 'schema' needs MySQL / MariaDB; use 'fresh' for SQLite.", 'warnings' => []]
                : $this->dumpMysql($config, $directory, (array) config('subandl.installer.schema_data_tables', ['migrations'])),
            'current' => $driver === 'sqlite'
                ? $this->copySqlite((string) ($config['database'] ?? ''), $directory)
                : $this->dumpMysql($config, $directory, null),
            default => ['ok' => false, 'message' => "Unknown database mode '{$mode}'.", 'warnings' => []],
        };
    }

    private function freshMysql(string $name, array $config, string $directory, bool $seed, ?string $scratchDatabase): array
    {
        $live = (string) ($config['database'] ?? '');
        $scratch = $scratchDatabase ?: $live . '_installer_' . Str::lower(Str::random(6));
        $created = false;

        if ($scratch === $live) {
            return ['ok' => false, 'message' => 'The scratch database must not be the project database.', 'warnings' => []];
        }

        if (! $scratchDatabase) {
            try {
                $charset = preg_replace('/[^a-z0-9_]/i', '', (string) ($config['charset'] ?? 'utf8mb4'));
                $collation = preg_replace('/[^a-z0-9_]/i', '', (string) ($config['collation'] ?? 'utf8mb4_unicode_ci'));
                DB::connection($name)->statement("CREATE DATABASE `{$scratch}` CHARACTER SET {$charset} COLLATE {$collation}");
                $created = true;
            } catch (\Throwable $e) {
                // Shared hosting users often can't create databases.
                $fallback = $this->dumpMysql($config, $directory, (array) config('subandl.installer.schema_data_tables', ['migrations']));
                $fallback['warnings'][] = "Could not create a scratch database ({$this->short($e)}). "
                    . 'Packaged the current structure without data instead (no seeders ran). '
                    . 'Create an empty database and pass --fresh-database=<name> for a real fresh install.';

                return $fallback;
            }
        }

        $scratchConfig = ['database' => $scratch] + $config;

        try {
            $migrated = $this->migrateInto($scratchConfig, $seed, wipe: ! $created);
            if (! $migrated['ok']) {
                return $migrated + ['warnings' => []];
            }

            return $this->dumpMysql($scratchConfig, $directory, null);
        } finally {
            if ($created) {
                try {
                    DB::connection($name)->statement("DROP DATABASE `{$scratch}`");
                } catch (\Throwable) {
                    // Left behind; harmless, and its name says what it was.
                }
            }
        }
    }

    private function freshSqlite(array $config, string $directory, bool $seed): array
    {
        $file = $directory . '/fresh-' . Str::lower(Str::random(6)) . '.sqlite';
        touch($file);

        $migrated = $this->migrateInto(['database' => $file] + $config, $seed, wipe: false);
        if (! $migrated['ok']) {
            @unlink($file);

            return $migrated + ['warnings' => []];
        }

        return ['ok' => true, 'path' => $file, 'extension' => 'sqlite', 'warnings' => []];
    }

    /**
     * Runs the migrations with the scratch connection as the default one, so
     * seeders that use models (default connection) write there too.
     */
    private function migrateInto(array $connection, bool $seed, bool $wipe): array
    {
        $previous = (string) config('database.default');
        config(['database.connections.' . self::SCRATCH_CONNECTION => $connection]);
        DB::purge(self::SCRATCH_CONNECTION);
        DB::setDefaultConnection(self::SCRATCH_CONNECTION);

        $output = new BufferedOutput();

        try {
            $code = Artisan::call($wipe ? 'migrate:fresh' : 'migrate', [
                '--database' => self::SCRATCH_CONNECTION,
                '--force' => true,
                '--seed' => $seed,
            ], $output);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Migrating the fresh database failed: ' . $e->getMessage()];
        } finally {
            DB::purge(self::SCRATCH_CONNECTION);
            DB::setDefaultConnection($previous);
        }

        return $code === 0
            ? ['ok' => true]
            : ['ok' => false, 'message' => "Migrating the fresh database failed:\n" . trim($output->fetch())];
    }

    private function dumpMysql(array $config, string $directory, ?array $dataTables): array
    {
        $path = $directory . '/database-' . Str::lower(Str::random(6)) . '.sql';
        $result = (new PhpMysqlDumper())->dump($config, $path, $dataTables);

        return $result['path']
            ? ['ok' => true, 'path' => $path, 'extension' => 'sql', 'warnings' => []]
            : ['ok' => false, 'message' => $result['message'] ?? 'Dump failed.', 'warnings' => []];
    }

    private function copySqlite(string $database, string $directory): array
    {
        $path = $directory . '/database-' . Str::lower(Str::random(6)) . '.sqlite';

        return is_file($database) && @copy($database, $path)
            ? ['ok' => true, 'path' => $path, 'extension' => 'sqlite', 'warnings' => []]
            : ['ok' => false, 'message' => "Could not copy SQLite database '{$database}'.", 'warnings' => []];
    }

    private function short(\Throwable $e): string
    {
        return Str::limit(trim(strtok($e->getMessage(), "\n") ?: ''), 160);
    }
}
