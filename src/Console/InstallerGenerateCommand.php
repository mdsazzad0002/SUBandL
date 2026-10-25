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
        {--variant=* : minimal, vendor, extended, uploads, node, full}
        {--all : Build every variant}
        {--with=* : A custom package: minimal plus these parts (vendor, assets, uploads, node)}
        {--update-from= : Also build an update package with only what changed since this version (or an earlier zip / manifest.json)}
        {--update-only : Build only the update package}
        {--db= : fresh (migrate + seed into a scratch database), schema, current (with data) or none}
        {--no-seed : With --db=fresh, run the migrations only}
        {--fresh-database= : An existing EMPTY database to migrate into, for hosts where a scratch database cannot be created}
        {--output= : Folder for the zips (default installers/ in the project root)}';

    protected $description = 'Package the project (fresh database included) into installer zips, and update zips with only what changed.';

    protected $aliases = ['subandl:installer-generate'];

    private const UPDATE_CHOICE = 'update';
    private const CUSTOM_CHOICE = 'custom';

    public function handle(): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->error('The PHP zip extension is required (enable extension=zip in php.ini).');

            return self::FAILURE;
        }

        Platform::allowLongRunning();

        $output = rtrim((string) ($this->option('output') ?: config('subandl.installer.output_path', base_path('installers'))), '/\\');

        $version = $this->askVersion();
        if ($version === null) {
            return self::FAILURE;
        }

        $selection = $this->askPackages($output, $version);
        if ($selection === null) {
            return self::FAILURE;
        }
        [$variants, $updateFrom] = $selection;

        $directory = $output . DIRECTORY_SEPARATOR . $version;
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true)) {
            $this->error("Could not create '{$directory}'.");

            return self::FAILURE;
        }

        $baseline = null;
        if ($updateFrom !== null) {
            $baseline = $this->loadBaseline($updateFrom, $output);
            if ($baseline === null || ! $baseline['files']) {
                $this->error("No earlier package found for '{$updateFrom}'. Give a version built here, or the path of an earlier zip or manifest.json.");

                return self::FAILURE;
            }
        }

        $database = null;
        $databasePath = trim((string) config('subandl.installer.database_path', 'database/installer/database.sql'), '/');
        $mode = 'none';
        $seed = false;

        if ($variants) {
            $mode = $this->option('db') ?: $this->choice('Database to include in the installers', [
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
            if ($mode === 'fresh' && $seed && $this->input->isInteractive()) {
                $seed = $this->confirm('Run the database seeders in the fresh database?', true);
            }

            $scratch = $this->option('fresh-database') ?: null;
            if ($mode === 'fresh' && $scratch !== null
                && ! $this->confirm("Every table in database '{$scratch}' will be DROPPED and recreated. Continue?", false)) {
                return self::FAILURE;
            }
            if ($mode === 'current') {
                $this->warn('The installers will contain all current data (users, sales, license state…). Share them with care.');
            }

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
        }

        try {
            return $this->buildZips($version, $variants, $directory, $output, $database, $databasePath, $mode, $seed, $baseline);
        } finally {
            if ($database) {
                @unlink($database);
            }
        }
    }

    /**
     * @param  array<string, list<string>>  $variants  name => included parts
     */
    private function buildZips(string $version, array $variants, string $directory, string $output, ?string $database, string $databasePath, string $mode, bool $seed, ?array $baseline): int
    {
        $root = base_path();
        $exclude = (array) config('subandl.installer.exclude', []);
        // Earlier packages (and copies unzipped next to them) in the default folder too.
        foreach ([$output, (string) config('subandl.installer.output_path', base_path('installers'))] as $folder) {
            $relativeOutput = $folder !== '' ? $this->relativeTo($root, $folder) : null;
            if ($relativeOutput !== null && $relativeOutput !== '') {
                $exclude[] = $relativeOutput;
            }
        }
        $exclude[] = $databasePath;
        // A leftover from an applied update; the updater would act on it again.
        $exclude[] = (string) config('subandl.update.remove_manifest', 'subandl-remove.txt');

        $assetPaths = (array) config('subandl.installer.asset_paths', ['resources/js', 'resources/css']);
        $uploadPaths = (array) config('subandl.installer.upload_paths', config('subandl.installer.private_paths', ['public/uploads']));
        $builder = new PackageBuilder($root, $exclude, $assetPaths, $uploadPaths);

        $this->info('Scanning the project…');
        $scan = $builder->scan();
        $this->line('  ' . number_format(count($scan['files'])) . ' files' . ($scan['skipped_links'] ? ", {$scan['skipped_links']} symlinks skipped" : ''));

        // What the next version's update is compared against.
        $prints = $builder->fingerprints($scan['files']);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'manifest.json', json_encode([
            'name' => config('app.name'),
            'version' => $version,
            'generated_at' => now()->toIso8601String(),
            'files' => $prints,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $hasBuild = is_file($root . '/public/build/manifest.json') || is_file($root . '/public/mix-manifest.json');
        $slug = Str::slug((string) config('app.name', 'app')) ?: 'app';
        $envExample = $this->envExample($root, $version);
        $checksums = [];

        if ($variants && ! $hasBuild) {
            $this->warn('  public/build is missing: run `npm run build` first, or packages without resources js/css have no frontend.');
        }

        foreach ($variants as $variant => $include) {
            $file = "{$slug}-v{$version}-{$variant}.zip";
            $strings = [
                'INSTALL.txt' => $this->instructions($version, $variant, $include, $database ? $databasePath : null, $mode, $seed),
                'installer.json' => json_encode([
                    'name' => config('app.name'),
                    'version' => $version,
                    'variant' => $variant,
                    'includes' => [
                        'vendor' => in_array(PackageBuilder::PART_VENDOR, $include, true),
                        'assets_source' => in_array(PackageBuilder::PART_ASSETS, $include, true),
                        'uploads' => in_array(PackageBuilder::PART_UPLOADS, $include, true),
                        'node_modules' => in_array(PackageBuilder::PART_NODE, $include, true),
                    ],
                    'database' => $database ? ['mode' => $mode, 'seeded' => $mode === 'fresh' && $seed, 'path' => $databasePath] : null,
                    'generated_at' => now()->toIso8601String(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            ];
            if ($envExample !== null) {
                $strings['.env.example'] = $envExample;
            }

            $result = $this->zip($builder, $directory, $file, $variant, $scan['files'], $include, $database ? [$databasePath => $database] : [], $strings, true);
            if ($result === null) {
                return self::FAILURE;
            }
            $checksums[$file] = $result;
        }

        if ($baseline !== null) {
            $from = $baseline['version'];
            $diff = $builder->diff($prints, $baseline['files'], true, ['INSTALL.txt', 'installer.json', 'subandl-remove.txt', $databasePath]);
            $this->info("Update from {$from}: " . count($diff['changed']) . ' changed, ' . count($diff['removed']) . " removed (vendor: {$diff['vendor']})");

            if (! $diff['changed'] && ! $diff['removed']) {
                $this->warn('  Nothing changed since that version; no update package built.');
            } else {
                $changed = array_flip($diff['changed']);
                $files = array_values(array_filter($scan['files'], fn ($f) => isset($changed[$f['rel']])));
                $strings = [];
                if ($diff['removed']) {
                    $strings[(string) config('subandl.update.remove_manifest', 'subandl-remove.txt')] =
                        "# Deleted since {$from}, removed by the updater.\n" . implode("\n", $diff['removed']) . "\n";
                }

                $file = "{$slug}-update-v{$from}-to-v{$version}.zip";
                $result = $this->zip($builder, $directory, $file, 'update', $files, [PackageBuilder::PART_VENDOR, PackageBuilder::PART_ASSETS], [], $strings, false);
                if ($result === null) {
                    return self::FAILURE;
                }
                $checksums[$file] = $result;
            }
        }

        $previous = is_file($directory . '/SHA256SUMS.txt') ? (array) file($directory . '/SHA256SUMS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $sums = [];
        foreach ($previous as $line) {
            [$hash, $name] = array_pad(preg_split('/\s+/', trim($line), 2), 2, '');
            if ($name !== '' && is_file($directory . '/' . $name)) {
                $sums[$name] = $hash;
            }
        }
        $sums = array_merge($sums, $checksums);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'SHA256SUMS.txt', implode('', array_map(fn ($name, $hash) => "{$hash}  {$name}\n", array_keys($sums), $sums)));

        $this->newLine();
        $this->info("Done. Packages are in {$directory}");

        return self::SUCCESS;
    }

    /** @return ?string the zip's SHA-256, null when it failed */
    private function zip(PackageBuilder $builder, string $directory, string $file, string $label, array $files, array $include, array $extraFiles, array $strings, bool $skeleton): ?string
    {
        $bar = $this->output->createProgressBar(100);
        $bar->setFormat("  {$label} [%bar%] %percent:3s%%");
        $bar->start();

        $result = $builder->build(
            $directory . DIRECTORY_SEPARATOR . $file,
            $files,
            $include,
            $extraFiles,
            $strings,
            fn (float $rate) => $bar->setProgress((int) round($rate * 100)),
            $skeleton,
        );

        $bar->finish();
        $this->newLine();

        if (! $result['ok']) {
            $this->error('  ' . $result['message']);

            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $file;
        $this->line("  <info>✓</info> {$file}  " . $this->size(filesize($path)) . ', ' . number_format($result['files']) . ' files');

        return hash_file('sha256', $path);
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

    /**
     * @return ?array{0: array<string, list<string>>, 1: ?string}  [variant => included parts, update-from]
     */
    private function askPackages(string $output, string $version): ?array
    {
        $names = (array) $this->option('variant');
        $with = (array) $this->option('with');
        $updateFrom = $this->option('update-from') ?: null;

        if ($this->option('all')) {
            $names[] = 'all';
        }

        if (! $names && ! $with && ! $updateFrom && ! $this->option('update-only')) {
            $choices = ['all' => 'All of the variants below']
                + PackageBuilder::VARIANT_LABELS
                + [self::CUSTOM_CHOICE => 'Custom: minimal plus the parts you pick', self::UPDATE_CHOICE => 'Update: only what changed since an earlier version'];
            $names = (array) $this->choice('Which packages? (comma separated)', $choices, 'all', null, true);

            if (in_array(self::CUSTOM_CHOICE, $names, true)) {
                $with = (array) $this->choice('Parts to add to minimal (comma separated)', PackageBuilder::PARTS, null, null, true);
            }
            if (in_array(self::UPDATE_CHOICE, $names, true)) {
                $updateFrom = $this->ask('Update from which version (or an earlier zip / manifest.json path)?', $this->previousVersion($output, $version));
            }
            $names = array_diff($names, [self::CUSTOM_CHOICE, self::UPDATE_CHOICE]);
        }

        if ($this->option('update-only') && ! $updateFrom) {
            $this->error('--update-only needs --update-from=<version>.');

            return null;
        }

        $names = array_values(array_unique(array_map('trim', $names)));
        if (in_array('all', $names, true)) {
            $names = array_keys(PackageBuilder::VARIANTS);
        }
        $unknown = array_diff($names, array_keys(PackageBuilder::VARIANTS));
        if ($unknown) {
            $this->error('Unknown variant: ' . implode(', ', $unknown) . '. Use: ' . implode(', ', array_keys(PackageBuilder::VARIANTS)));

            return null;
        }

        $variants = [];
        if (! $this->option('update-only')) {
            foreach ($names as $name) {
                $variants[$name] = PackageBuilder::VARIANTS[$name];
            }

            if ($with) {
                $with = array_values(array_unique(array_map('trim', $with)));
                $unknownParts = array_diff($with, array_keys(PackageBuilder::PARTS));
                if ($unknownParts) {
                    $this->error('Unknown part: ' . implode(', ', $unknownParts) . '. Use: ' . implode(', ', array_keys(PackageBuilder::PARTS)));

                    return null;
                }
                // Canonical order, so the same parts always give the same name.
                $parts = array_values(array_intersect(array_keys(PackageBuilder::PARTS), $with));
                $preset = array_search($parts, PackageBuilder::VARIANTS, true);
                $variants[$preset !== false ? $preset : 'minimal-' . implode('-', $parts)] = $parts;
            }
        }

        if (! $variants && ! $updateFrom) {
            $this->error('Nothing to build: pick a variant, --with parts or --update-from.');

            return null;
        }

        return [$variants, $updateFrom ? trim((string) $updateFrom) : null];
    }

    /** The newest earlier version built in the output folder, as the default to update from. */
    private function previousVersion(string $output, string $version): ?string
    {
        $versions = array_filter(
            array_map('basename', glob($output . '/*', GLOB_ONLYDIR) ?: []),
            fn ($v) => $v !== $version && (is_file("{$output}/{$v}/manifest.json") || glob("{$output}/{$v}/*.zip"))
                && version_compare($v, $version, '<')
        );
        usort($versions, 'version_compare');

        return $versions ? end($versions) : null;
    }

    /**
     * An earlier release's files: its manifest.json, or the entries of one of
     * its zips (the most complete one, so vendor can be compared file by file).
     *
     * @return ?array{version: string, files: array<string, array{0: string, 1: int}>}
     */
    private function loadBaseline(string $from, string $output): ?array
    {
        // Label for the update's name: the version, also when given a file path.
        $label = is_file($from)
            ? (preg_match('/-v(\d[0-9A-Za-z.+]*)/', basename($from), $m) ? $m[1] : pathinfo($from, PATHINFO_FILENAME))
            : ltrim($from, 'vV');

        $candidates = [];
        if (is_file($from)) {
            $candidates[] = $from;
        } else {
            $version = ltrim($from, 'vV');
            foreach ([$version, 'v' . $version] as $dir) {
                $candidates[] = "{$output}/{$dir}/manifest.json";
                foreach (['full', 'node', 'uploads', 'extended', 'vendor', 'minimal'] as $variant) {
                    foreach (glob("{$output}/{$dir}/*-v{$version}-{$variant}.zip") ?: [] as $zip) {
                        $candidates[] = $zip;
                    }
                }
                // Zips from older builds (full, no-node, …).
                foreach (glob("{$output}/{$dir}/*.zip") ?: [] as $zip) {
                    if (! str_contains(basename($zip), '-update-')) {
                        $candidates[] = $zip;
                    }
                }
            }
        }

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }
            if (str_ends_with(strtolower($path), '.json')) {
                $json = json_decode((string) file_get_contents($path), true);
                if (is_array($json['files'] ?? null)) {
                    $this->line('  Comparing with ' . $path);

                    return ['version' => (string) ($json['version'] ?? $label), 'files' => $json['files']];
                }

                continue;
            }

            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                continue;
            }
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat && ! str_ends_with($stat['name'], '/')) {
                    $files[$stat['name']] = [sprintf('%08x', $stat['crc'] & 0xFFFFFFFF), (int) $stat['size']];
                }
            }
            $zip->close();
            $this->line('  Comparing with ' . $path);

            return ['version' => $label, 'files' => $files];
        }

        return null;
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

    private function instructions(string $version, string $variant, array $include, ?string $databasePath, string $mode, bool $seed): string
    {
        $name = (string) config('app.name', 'Application');
        $vendor = in_array(PackageBuilder::PART_VENDOR, $include, true);
        $node = in_array(PackageBuilder::PART_NODE, $include, true);
        $assets = in_array(PackageBuilder::PART_ASSETS, $include, true);
        $uploads = in_array(PackageBuilder::PART_UPLOADS, $include, true);

        $steps = [
            'Extract this zip into the project folder (the web server points at public/).',
            'Copy .env.example to .env and set APP_URL and the DB_* values.',
        ];
        if (! $vendor) {
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
        $steps[] = 'Make storage/, bootstrap/cache/ and the upload folders writable by the web server.';
        if (! $uploads) {
            $steps[] = 'Upload your own logo and favicon from the settings page.';
        }

        $frontend = ! $assets
            ? 'The frontend is prebuilt in public/; the resources js/css sources are not included.'
            : ($node
                ? 'The frontend is prebuilt in public/. To change it: npm run build'
                : 'The frontend is prebuilt in public/. To change it: npm install && npm run build');

        $lines = ["{$name} v{$version} ({$variant})", str_repeat('=', 40), ''];
        $lines[] = 'Includes: vendor ' . ($vendor ? 'yes' : 'no')
            . ', resources js/css ' . ($assets ? 'yes' : 'no')
            . ', uploads ' . ($uploads ? 'yes' : 'no')
            . ', node_modules ' . ($node ? 'yes' : 'no')
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
