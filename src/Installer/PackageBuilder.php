<?php

namespace SUBandL\Installer;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Builds the distributable zips for `php artisan installergenerate`.
 *
 * The project is scanned once; every file is tagged with the optional part
 * it belongs to (vendor, frontend sources, uploads, node_modules), and each
 * variant is the core (app, bootstrap, config, database, public, routes,
 * views, root files…) plus the parts it includes. Secrets (.env), VCS data
 * and the contents of storage/ and bootstrap/cache never go in.
 */
class PackageBuilder
{
    public const PART_VENDOR = 'vendor';
    public const PART_ASSETS = 'assets';
    public const PART_UPLOADS = 'uploads';
    public const PART_NODE = 'node';

    /** Only the folder skeleton (.gitignore files): in every installer, never in an update. */
    public const PART_RUNTIME = 'runtime';

    public const PARTS = [
        self::PART_VENDOR => 'vendor',
        self::PART_ASSETS => 'resources js/css',
        self::PART_UPLOADS => 'uploads (logo, favicon, documents)',
        self::PART_NODE => 'node_modules',
    ];

    /** Variant name => the parts it includes on top of the core. */
    public const VARIANTS = [
        'minimal' => [],
        'vendor' => [self::PART_VENDOR],
        'extended' => [self::PART_VENDOR, self::PART_ASSETS],
        'uploads' => [self::PART_VENDOR, self::PART_ASSETS, self::PART_UPLOADS],
        'node' => [self::PART_VENDOR, self::PART_ASSETS, self::PART_NODE],
        'full' => [self::PART_VENDOR, self::PART_ASSETS, self::PART_UPLOADS, self::PART_NODE],
    ];

    public const VARIANT_LABELS = [
        'minimal' => 'Minimal: app, config, database, public build, views… (no vendor, js/css, uploads)',
        'vendor' => 'Minimal + vendor',
        'extended' => 'Minimal + vendor + resources js/css',
        'uploads' => 'Extended + uploads (logo, favicon, documents)',
        'node' => 'Extended + node_modules',
        'full' => 'Everything: vendor, js/css, uploads and node_modules',
    ];

    /** Never packaged. */
    private const EXCLUDE = [
        '.git', '.svn', '.idea', '.vscode', '.fleet', '.claude',
        '.phpunit.result.cache', '.phpunit.cache',
        'node_modules/.cache', 'node_modules/.vite',
        'public/hot', 'public/storage',
        // The root .htaccess is each host's own (e.g. rewriting into public/ on
        // shared hosting). public/.htaccess is Laravel's and stays in.
        '.htaccess',
        // Packages built before the default moved to installers/.
        'storage/installers',
    ];

    /**
     * Only their .gitignore files go in: storage/ is runtime data (logs,
     * sessions, backups), never part of a release.
     */
    private const CONTENT_ONLY = [
        'storage',
        'bootstrap/cache',
    ];

    private const JUNK_FILES = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];

    private array $exclude;
    private array $assetPaths;
    private array $uploadPaths;

    /** The content-only roots and every folder under an upload path, created empty in every installer. */
    private array $emptyDirs = [];

    public function __construct(private readonly string $root, array $extraExclude = [], array $assetPaths = [], array $uploadPaths = [])
    {
        $this->exclude = array_values(array_filter(array_map([$this, 'normalize'], [...self::EXCLUDE, ...$extraExclude])));
        $this->assetPaths = array_values(array_filter(array_map([$this, 'normalize'], $assetPaths)));
        $this->uploadPaths = array_values(array_filter(array_map([$this, 'normalize'], $uploadPaths)));
    }

    /**
     * @return array{files: list<array{abs: string, rel: string, part: ?string, exec: bool}>, skipped_links: int}
     */
    public function scan(): array
    {
        $root = rtrim($this->root, '/\\');
        $skippedLinks = 0;

        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
        $filter = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $file) use ($root, &$skippedLinks) {
            $rel = $this->relative($root, $file->getPathname());

            if ($file->isLink()) {
                // node_modules/.bin, public/storage: links don't survive a zip.
                $skippedLinks++;

                return false;
            }

            return $file->isDir() ? ! $this->isExcluded($rel) : $this->includeFile($rel, $file->getFilename());
        });

        $files = [];
        $this->emptyDirs = array_values(array_filter(
            [...self::CONTENT_ONLY, ...$this->uploadPaths],
            fn ($dir) => is_dir($root . '/' . $dir)
        ));
        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $file) {
            /** @var SplFileInfo $file */
            $rel = $this->relative($root, $file->getPathname());
            if ($file->isDir()) {
                // Upload code often expects its folder (uploads/employee/documents) to exist.
                if ($this->under($rel, $this->uploadPaths)) {
                    $this->emptyDirs[] = $rel;
                }

                continue;
            }
            $files[] = [
                'abs' => $file->getPathname(),
                'rel' => $rel,
                'part' => $this->partOf($rel),
                'exec' => ($file->getPerms() & 0111) !== 0,
            ];
        }

        usort($files, fn ($a, $b) => strcmp($a['rel'], $b['rel']));

        return ['files' => $files, 'skipped_links' => $skippedLinks];
    }

    /**
     * What an update compares: the core, vendor and the frontend sources —
     * never uploads, node_modules or storage.
     *
     * @return array<string, array{0: string, 1: int}>  path => [crc32, size]
     */
    public function fingerprints(array $files): array
    {
        $prints = [];
        foreach ($files as $file) {
            if (in_array($file['part'], [null, self::PART_VENDOR, self::PART_ASSETS], true) && is_file($file['abs'])) {
                $prints[$file['rel']] = [hash_file('crc32b', $file['abs']), (int) filesize($file['abs'])];
            }
        }

        return $prints;
    }

    /**
     * Files to ship in an update from $baseline, and the ones to delete.
     * vendor/ is compared file by file when the baseline has it; otherwise
     * it goes in whole only when composer.lock changed.
     *
     * @param  array<string, array{0: string, 1: int}>  $current
     * @param  array<string, array{0: string, 1: int}>  $baseline
     * @return array{changed: list<string>, removed: list<string>, vendor: string}
     */
    public function diff(array $current, array $baseline, bool $withAssets, array $ignore = []): array
    {
        $baselineHasVendor = false;
        foreach ($baseline as $rel => $_) {
            if (str_starts_with($rel, 'vendor/')) {
                $baselineHasVendor = true;

                break;
            }
        }
        $lockChanged = ($current['composer.lock'] ?? null) !== ($baseline['composer.lock'] ?? null);
        $vendor = $baselineHasVendor ? 'compared' : ($lockChanged ? 'composer.lock changed' : 'unchanged');

        $considered = function (string $rel) use ($withAssets, $baselineHasVendor, $ignore): bool {
            if (in_array($rel, $ignore, true)) {
                return false;
            }

            return match ($this->partOf($rel)) {
                null => true,
                self::PART_VENDOR => $baselineHasVendor,
                self::PART_ASSETS => $withAssets,
                default => false,
            };
        };

        $changed = [];
        foreach ($current as $rel => $print) {
            $isVendor = str_starts_with($rel, 'vendor/');
            if ($isVendor && ! $baselineHasVendor) {
                if ($lockChanged) {
                    $changed[] = $rel;
                }

                continue;
            }
            if ($considered($rel) && ($baseline[$rel] ?? null) !== $print) {
                $changed[] = $rel;
            }
        }

        $removed = [];
        foreach ($baseline as $rel => $_) {
            // Only what the project really dropped; an excluded file that still exists stays put.
            if (! isset($current[$rel]) && $considered($rel) && ! file_exists($this->root . '/' . $rel)) {
                $removed[] = $rel;
            }
        }

        return ['changed' => $changed, 'removed' => $removed, 'vendor' => $vendor];
    }

    /**
     * @param  list<array{abs: string, rel: string, part: ?string, exec: bool}>  $files
     * @param  list<string>  $include  optional parts to include
     * @param  array<string, string>  $extraFiles  zip path => absolute source file
     * @param  array<string, string>  $extraStrings  zip path => contents
     * @return array{ok: bool, files?: int, message?: string}
     */
    public function build(string $zipPath, array $files, array $include, array $extraFiles = [], array $extraStrings = [], ?callable $progress = null, bool $skeleton = true, bool $retry = true): array
    {
        $tmp = $zipPath . '.part';
        @unlink($tmp);

        $zip = new ZipArchive();
        if (($code = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
            return ['ok' => false, 'message' => "Could not create '{$tmp}' (ZipArchive error {$code})."];
        }

        $count = 0;
        foreach ($files as $file) {
            $part = $file['part'];
            if ($part !== null && ! ($part === self::PART_RUNTIME ? $skeleton : in_array($part, $include, true))) {
                continue;
            }
            if (isset($extraFiles[$file['rel']]) || isset($extraStrings[$file['rel']])) {
                continue;
            }
            $zip->addFile($file['abs'], $file['rel']);
            if ($file['exec']) {
                $zip->setExternalAttributesName($file['rel'], ZipArchive::OPSYS_UNIX, (0100755 << 16));
            }
            $count++;
        }

        if ($skeleton) {
            foreach ($this->emptyDirs as $dir) {
                $zip->addEmptyDir($dir);
            }
        }
        foreach ($extraFiles as $rel => $source) {
            $zip->addFile($source, $rel);
            $count++;
        }
        foreach ($extraStrings as $rel => $contents) {
            $zip->addFromString($rel, $contents);
            $count++;
        }

        if ($progress && method_exists($zip, 'registerProgressCallback')) {
            $zip->registerProgressCallback(0.01, $progress);
        }

        try {
            $closed = $zip->close();
            $error = $closed ? '' : $zip->getStatusString();
        } catch (\Throwable $e) {
            $closed = false;
            $error = $e->getMessage();
        }

        if (! $closed) {
            @unlink($tmp);

            // A file deleted while zipping (a cache the running app prunes): once more without it.
            $present = array_values(array_filter($files, fn ($file) => is_file($file['abs'])));
            if ($retry && count($present) < count($files)) {
                return $this->build($zipPath, $present, $include, $extraFiles, $extraStrings, $progress, $skeleton, false);
            }

            return ['ok' => false, 'message' => "Writing '{$zipPath}' failed: {$error}"];
        }

        @unlink($zipPath);
        if (! @rename($tmp, $zipPath)) {
            @unlink($tmp);

            return ['ok' => false, 'message' => "Could not move the finished zip to '{$zipPath}'."];
        }

        return ['ok' => true, 'files' => $count];
    }

    private function includeFile(string $rel, string $name): bool
    {
        if (in_array($name, self::JUNK_FILES, true) || $this->isExcluded($rel)) {
            return false;
        }

        // .env, .env.backup, .env.production… hold secrets; .env.example is the template.
        if (! str_contains($rel, '/') && preg_match('/^\.env(\..+)?$/', $rel) && $rel !== '.env.example') {
            return false;
        }

        return ! $this->under($rel, self::CONTENT_ONLY) || $name === '.gitignore';
    }

    private function isExcluded(string $rel): bool
    {
        foreach ($this->exclude as $path) {
            if ($rel === $path || str_starts_with($rel, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    private function partOf(string $rel): ?string
    {
        return match (true) {
            str_starts_with($rel, 'node_modules/') => self::PART_NODE,
            str_starts_with($rel, 'vendor/') => self::PART_VENDOR,
            $this->under($rel, self::CONTENT_ONLY) => self::PART_RUNTIME,
            $this->under($rel, $this->uploadPaths) => self::PART_UPLOADS,
            $this->under($rel, $this->assetPaths) => self::PART_ASSETS,
            default => null,
        };
    }

    private function under(string $rel, array $paths): bool
    {
        foreach ($paths as $path) {
            if (str_starts_with($rel, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $root, string $path): string
    {
        return str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/\\'));
    }

    private function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
