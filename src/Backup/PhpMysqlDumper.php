<?php

namespace SUBandL\Backup;

use PDO;

/**
 * A mysqldump-compatible SQL dump written in plain PHP (PDO), for hosts where
 * the mysqldump binary is missing or can't be run (proc_open disabled on
 * shared hosting, a WAMP/XAMPP install without the client on the PATH).
 *
 * Tables (structure + data), views and triggers, restorable with the mysql
 * client or phpMyAdmin. Rows are read unbuffered and written as batched
 * multi-row INSERTs straight to disk, so memory stays flat however large the
 * database is. Binary columns are written as hex; generated columns are
 * left out of the INSERTs (MySQL computes them).
 */
class PhpMysqlDumper
{
    private const BATCH_BYTES = 1024 * 1024;
    private const BATCH_ROWS = 500;

    private const BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring',
        'multipolygon', 'geometrycollection',
    ];

    private PDO $pdo;

    /**
     * @return array{path: ?string, message?: string}
     */
    public function dump(array $config, string $path): array
    {
        $out = @fopen($path, 'wb');
        if ($out === false) {
            return ['path' => null, 'message' => "Could not write '{$path}'."];
        }

        try {
            $this->pdo = $this->connect($config);
            $database = (string) ($config['database'] ?? '');

            $this->write($out, $this->header($database));

            $tables = $this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            $views = [];

            foreach ($tables as [$name, $type]) {
                if (strtoupper((string) $type) === 'VIEW') {
                    $views[] = $name;

                    continue;
                }
                $this->dumpTable($out, $database, $name);
            }

            foreach ($views as $view) {
                $this->dumpView($out, $view);
            }

            $this->dumpTriggers($out);

            $this->write($out, "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- Dump completed " . gmdate('Y-m-d H:i:s') . " UTC\n");
            fclose($out);

            return ['path' => $path];
        } catch (\Throwable $e) {
            @fclose($out);
            @unlink($path);

            return ['path' => null, 'message' => 'PHP dump failed: ' . $e->getMessage()];
        }
    }

    private function connect(array $config): PDO
    {
        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $dsn = ! empty($config['unix_socket'])
            ? "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']};charset={$charset}"
            : 'mysql:host=' . ($config['host'] ?? '127.0.0.1') . ';port=' . ($config['port'] ?? 3306) . ";dbname={$config['database']};charset={$charset}";

        // PHP 8.4 moved the MySQL constants to Pdo\Mysql.
        $unbuffered = defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;

        $options = (array) ($config['options'] ?? []) + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
        ];
        $options[$unbuffered] = false;

        $pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);
        // Same as mysqldump: TIMESTAMP values are dumped in UTC and restored as UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = ''");

        return $pdo;
    }

    private function header(string $database): string
    {
        $version = $this->pdo->query('SELECT VERSION()')->fetchColumn();

        return "-- SUBandL PHP dump\n"
            . "-- Database: {$database}\n"
            . "-- Server version: {$version}\n"
            . '-- Generated: ' . gmdate('Y-m-d H:i:s') . " UTC\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET time_zone = '+00:00';\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET UNIQUE_CHECKS=0;\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    }

    private function dumpTable($out, string $database, string $table): void
    {
        $q = $this->ident($table);
        $create = $this->pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
        $this->write($out, "\n--\n-- Table structure for {$q}\n--\n\nDROP TABLE IF EXISTS {$q};\n{$create};\n");

        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$database, $table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $col = array_change_key_case($col, CASE_LOWER);
            if (stripos((string) $col['extra'], 'GENERATED') !== false) {
                continue;
            }
            $columns[] = ['name' => $col['column_name'], 'binary' => in_array(strtolower((string) $col['data_type']), self::BINARY_TYPES, true)];
        }
        if (! $columns) {
            return;
        }

        $list = implode(', ', array_map(fn ($c) => $this->ident($c['name']), $columns));
        $select = implode(', ', array_map(
            fn ($c) => $c['binary'] ? 'HEX(' . $this->ident($c['name']) . ')' : $this->ident($c['name']),
            $columns
        ));
        $insert = "INSERT INTO {$q} ({$list}) VALUES\n";

        $rows = $this->pdo->query("SELECT {$select} FROM {$q}");
        $rows->setFetchMode(PDO::FETCH_NUM);

        $batch = [];
        $bytes = 0;
        $wroteHeader = false;
        foreach ($rows as $row) {
            if (! $wroteHeader) {
                $this->write($out, "\n--\n-- Data for {$q}\n--\n\nLOCK TABLES {$q} WRITE;\n");
                $wroteHeader = true;
            }

            $values = [];
            foreach ($row as $i => $value) {
                $values[] = match (true) {
                    $value === null => 'NULL',
                    $columns[$i]['binary'] => $value === '' ? "''" : '0x' . $value,
                    default => $this->pdo->quote((string) $value),
                };
            }
            $tuple = '(' . implode(',', $values) . ')';
            $batch[] = $tuple;
            $bytes += strlen($tuple);

            if (count($batch) >= self::BATCH_ROWS || $bytes >= self::BATCH_BYTES) {
                $this->write($out, $insert . implode(",\n", $batch) . ";\n");
                $batch = [];
                $bytes = 0;
            }
        }
        $rows->closeCursor();

        if ($batch) {
            $this->write($out, $insert . implode(",\n", $batch) . ";\n");
        }
        if ($wroteHeader) {
            $this->write($out, "UNLOCK TABLES;\n");
        }
    }

    private function dumpView($out, string $view): void
    {
        $q = $this->ident($view);
        $create = $this->pdo->query("SHOW CREATE VIEW {$q}")->fetch(PDO::FETCH_NUM)[1];
        $this->write($out, "\n--\n-- View {$q}\n--\n\nDROP VIEW IF EXISTS {$q};\n" . $this->withoutDefiner($create) . ";\n");
    }

    private function dumpTriggers($out): void
    {
        $triggers = $this->pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($triggers as $trigger) {
            $trigger = array_change_key_case($trigger, CASE_LOWER);
            $q = $this->ident($trigger['trigger']);
            $create = $this->pdo->query("SHOW CREATE TRIGGER {$q}")->fetch(PDO::FETCH_ASSOC);
            $create = array_change_key_case($create, CASE_LOWER)['sql original statement'] ?? null;
            if (! $create) {
                continue;
            }
            $this->write($out, "\nDROP TRIGGER IF EXISTS {$q};\nDELIMITER ;;\n" . $this->withoutDefiner($create) . ";;\nDELIMITER ;\n");
        }
    }

    /** The original DEFINER user rarely exists where the dump is restored. */
    private function withoutDefiner(string $sql): string
    {
        return (string) preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\S+)@(`[^`]*`|\S+)/i', '', $sql);
    }

    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function write($out, string $text): void
    {
        if (fwrite($out, $text) !== strlen($text)) {
            throw new \RuntimeException('Disk full or not writable while writing the dump.');
        }
    }
}
