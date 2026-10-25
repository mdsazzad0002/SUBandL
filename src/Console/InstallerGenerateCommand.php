<?php

namespace SUBandL\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SUBandL\Installer\DatabaseSnapshot;
use SUBandL\Installer\PackageBuilder;
use SUBandL\Support\Platform;
use ZipArchive;

class InstallerGenerateCommand extends Command
{
    protected $signature = 'installergenerate
        {--release= : Version of this package, e.g. 1.9.0 (asked when left out)}
        {--variant=* : full, no-node, no-vendor, no-assets, no-node-vendor, no-node-assets, no-vendor-assets, minimal}
        {--all : Build every variant}
        {--db= : fresh (migrate + seed into a scratch database), schema, current (with data) or none}
        {--no-seed : With --db=fresh, run the migrations only}
        {--fresh-database= : An existing EMPTY database to migrate into, for hosts where a scratch database cannot be created}
        {--output= : Folder for the zips (default storage/installers)}';

    protected $description = 'Package the project (fresh database included) into distributable installer zips.';

    protected $aliases = ['subandl:installer-generate'];

    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->error('The PHP zip extension is required (enable extension=zip in php.ini).');

            return self::FAILURE;
        }

        Platform::allowLongRunning();

        $version = $this->askVersion();
        if ($version === null) {
            return self::FAILURE;
        }

        $variants = $this->askVariants();
        if ($variants === null) {
            return self::FAILURE;
        }

        $mode = $this->option('db') ?: $this->choice('Database to include', [
            'fresh' => 'Fresh: run migrations + seeders into a scratch database (recommended)',
            'schema' => 'Structure of the current database, no data',
            'current' => 'Current database WITH its data',
            'none' => 'No database',
        ], 'fresh');
        if (! in_array($mode, DatabaseSnapshot::MODES, true)) {
            $this->error('--db must be one of: ' . implode(', ', DatabaseSnapshot::MODES));

            return self::FAILURE;
        }

        $seed = ! $this->option('no-seed');
        if ($mode === 'fresh' && ! $this->option('no-seed') && $this->input->isInteractive()) {
            $seed = $this->confirm('Run the database seeders in the fresh database?', true);
        }

        $scratch = $this->option('fresh-database') ?: null;
        if ($mode === 'fresh' && $scratch !== null
            && ! $this->confirm("Every table in database '{$scratch}' will be DROPPED and recreated. Continue?", false)) {
            return self::FAILURE;
        }
        if ($mode === 'current') {
            $this->warn('The package will contain all current data (users, sales, license state…). Share it with care.');
        }

        $output = rtrim((string) ($this->option('output') ?: config('subandl.installer.output_path', storage_path('installers'))), '/\\');
        $directory = $output . DIRECTORY_SEPARATOR . $version;
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true)) {
            $this->error("Could not create '{$directory}'.");

            return self::FAILURE;
        }

        // 1. Database
        $database = null;
        $databasePath = trim((string) config('subandl.installer.database_path', 'database/installer/database.sql'), '/');
        if ($mode !== 'none') {
            $this->info($mode === 'fresh' ? 'Building a fresh database…' : "Dumping the database ({$mode})…");
            $snapshot = (new DatabaseSnapshot())->make($mode, $directory, $seed, $scratch);
            foreach ($snapshot['warnings'] as $warning) {
                $this->warn($warning);
            }
            if (! $snapshot['ok']) {
                $this->error($snapshot['message']);

                return self::FAILURE;
            }
            $database = $snapshot['path'];
            $databasePath = preg_replace('/\.[a-z0-9]+$/i', '', $databasePath) . '.' . $snapshot['extension'];
            $this->line('  Database: ' . $this->size(filesize($database)));
        }

        try {
            return $this->buildZips($version, $variants, $directory, $output, $database, $databasePath, $mode, $seed);
        } finally {
            if ($database) {
                @unlink($database);
            }
        }
    }

    private function buildZips(string $version, array $variants, string $directory, string $output, ?string $database, string $databasePath, string $mode, bool $seed): int
    {
        $root = base_path();
        $exclude = (array) config('subandl.installer.exclude', []);
        $relativeOutput = $this->relativeTo($root, $output);
        if ($relativeOutput !== null) {
            $exclude[] = $relativeOutput;
        }
        if ($database) {
            $exclude[] = $databasePath;
        }

        $assetPaths = (array) config('subandl.installer.asset_paths', ['resources/js', 'resources/css']);
        $builder = new PackageBuilder($root, $exclude, $assetPaths);

        $this->info('Scanning the project…');
        $scan = $builder->scan();
        $this->line('  ' . number_format(count($scan['files'])) . ' files' . ($scan['skipped_links'] ? ", {$scan['skipped_links']} symlinks skipped" : ''));

        $hasBuild = is_file($root . '/public/build/manifest.json') || is_file($root . '/public/mix-manifest.json');
        $slug = Str::slug((string) config('app.name', 'app')) ?: 'app';
        $envExample = $this->envExample($root, $version);
        $checksums = [];

        foreach ($variants as $variant) {
            $leaveOut = PackageBuilder::VARIANTS[$variant];
            if (in_array(PackageBuilder::PART_ASSETS, $leaveOut, true) && ! $hasBuild) {
                $this->warn("  {$variant}: public/build is missing; run `npm run build` first or the package has no frontend.");
            }

            $file = "{$slug}-v{$version}-{$variant}.zip";
            $strings = [
                'INSTALL.txt' => $this->instructions($version, $variant, $leaveOut, $database ? $databasePath : null, $mode, $seed),
                'installer.json' => json_encode([
                    'name' => config('app.name'),
                    'version' => $version,
                    'variant' => $variant,
                    'includes' => [
                        'vendor' => ! in_array(PackageBuilder::PART_VENDOR, $leaveOut, true),
                        'node_modules' => ! in_array(PackageBuilder::PART_NODE, $leaveOut, true),
                        'assets_source' => ! in_array(PackageBuilder::PART_ASSETS, $leaveOut, true),
                    ],
                    'database' => $database ? ['mode' => $mode, 'seeded' => $mode === 'fresh' && $seed, 'path' => $databasePath] : null,
                    'generated_at' => now()->toIso8601String(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            ];
            if ($envExample !== null) {
                $strings['.env.example'] = $envExample;
            }

            $bar = $this->output->createProgressBar(100);
            $bar->setFormat("  {$variant} [%bar%] %percent:3s%%");
            $bar->start();

            $result = $builder->build(
                $directory . DIRECTORY_SEPARATOR . $file,
                $scan['files'],
                $leaveOut,
                $database ? [$databasePath => $database] : [],
                $strings,
                fn (float $rate) => $bar->setProgress((int) round($rate * 100)),
            );

            $bar->finish();
            $this->newLine();

            if (! $result['ok']) {
                $this->error('  ' . $result['message']);

                return self::FAILURE;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $file;
            $checksums[$file] = hash_file('sha256', $path);
            $this->line("  <info>✓</info> {$file}  " . $this->size(filesize($path)) . ', ' . number_format($result['files']) . ' files');
        }

        $sums = '';
        foreach ($checksums as $file => $hash) {
            $sums .= "{$hash}  {$file}\n";
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'SHA256SUMS.txt', $sums);

        $this->newLine();
        $this->info("Done. Packages are in {$directory}");

        return self::SUCCESS;
    }

    private function askVersion(): ?string
    {
        $version = (string) ($this->option('release') ?: $this->ask('Version of this package', (string) config('subandl.version', '1.0.0')));
        $version = ltrim(trim($version), 'vV');

        if (! preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-+]*$/', $version)) {
            $this->error("'{$version}' is not a valid version (use something like 1.9.0).");

            return null;
        }

        return $version;
    }

    private function askVariants(): ?array
    {
        if ($this->option('all')) {
            return array_keys(PackageBuilder::VARIANTS);
        }

        $variants = (array) $this->option('variant');
        if (! $variants) {
            $choices = ['all' => 'All of the variants below'] + PackageBuilder::VARIANT_LABELS;
            $variants = (array) $this->choice('Which packages? (comma separated)', $choices, 'all', null, true);
        }

        $variants = array_values(array_unique(array_map('trim', $variants)));
        if (in_array('all', $variants, true)) {
            return array_keys(PackageBuilder::VARIANTS);
        }

        $unknown = array_diff($variants, array_keys(PackageBuilder::VARIANTS));
        if ($unknown) {
            $this->error('Unknown variant: ' . implode(', ', $unknown) . '. Use: ' . implode(', ', array_keys(PackageBuilder::VARIANTS)));

            return null;
        }

        return $variants;
    }

    /** .env.example with APP_VERSION set to this release, so new installs report it. */
    private function envExample(string $root, string $version): ?string
    {
        $path = $root . '/.env.example';
        $key = (string) config('subandl.version_env_key', 'APP_VERSION');
        if (! is_file($path) || $key === '') {
            return null;
        }

        $content = (string) file_get_contents($path);
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $pattern = '/^' . preg_quote($key, '/') . '=[^\r\n]*/m';

        return preg_match($pattern, $content)
            ? (string) preg_replace($pattern, $key . '=' . $version, $content)
            : rtrim($content) . $eol . $key . '=' . $version . $eol;
    }

    private function instructions(string $version, string $variant, array $leaveOut, ?string $databasePath, string $mode, bool $seed): string
    {
        $name = (string) config('app.name', 'Application');
        $noVendor = in_array(PackageBuilder::PART_VENDOR, $leaveOut, true);
        $noNode = in_array(PackageBuilder::PART_NODE, $leaveOut, true);
        $noAssets = in_array(PackageBuilder::PART_ASSETS, $leaveOut, true);

        $steps = [
            'Extract this zip into the project folder (the web server points at public/).',
            'Copy .env.example to .env and set APP_URL and the DB_* values.',
        ];
        if ($noVendor) {
            $steps[] = 'Install the PHP dependencies: composer install --no-dev --optimize-autoloader';
        }
        $steps[] = 'Generate the app key: php artisan key:generate';
        if ($databasePath && str_ends_with($databasePath, '.sql')) {
            $steps[] = "Import {$databasePath} into the empty database (phpMyAdmin or: mysql -u USER -p DATABASE < {$databasePath})";
            $steps[] = 'Then run any newer migrations: php artisan migrate --force';
        } elseif ($databasePath) {
            $steps[] = "Copy {$databasePath} to database/database.sqlite (or point DB_DATABASE at it), then: php artisan migrate --force";
        } else {
            $steps[] = 'Create the tables: php artisan migrate --seed --force';
        }
        $steps[] = 'Link public storage: php artisan storage:link';
        $steps[] = 'Make storage/ and bootstrap/cache/ writable by the web server.';

        $frontend = $noAssets
            ? 'The frontend is prebuilt in public/; the resources js/css sources are not included.'
            : ($noNode
                ? 'The frontend is prebuilt in public/. To change it: npm install && npm run build'
                : 'The frontend is prebuilt in public/. To change it: npm run build');

        $lines = ["{$name} v{$version} ({$variant})", str_repeat('=', 40), ''];
        $lines[] = 'Includes: vendor ' . ($noVendor ? 'no' : 'yes')
            . ', node_modules ' . ($noNode ? 'no' : 'yes')
            . ', resources js/css ' . ($noAssets ? 'no' : 'yes')
            . ', database ' . ($databasePath ? $mode . ($mode === 'fresh' && $seed ? ' (seeded)' : '') : 'no');
        $lines[] = '';
        foreach ($steps as $i => $step) {
            $lines[] = ($i + 1) . '. ' . $step;
        }
        $lines[] = '';
        $lines[] = $frontend;

        return implode("\n", $lines) . "\n";
    }

    private function relativeTo(string $root, string $path): ?string
    {
        $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/') . '/';
        $path = str_replace('\\', '/', realpath($path) ?: $path);

        return str_starts_with($path . '/', $root) ? trim(substr($path, strlen($root)), '/') : null;
    }

    private function size(int|false $bytes): string
    {
        $bytes = (int) $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return '';
    }
}
