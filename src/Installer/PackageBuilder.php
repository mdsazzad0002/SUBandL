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
 * it belongs to (node_modules, vendor, frontend sources), and each variant
 * is the same list minus the parts it leaves out. Secrets (.env), VCS data,
 * logs, caches and compiled views never go in.
 */
class PackageBuilder
{
    public const PART_NODE = 'node';
    public const PART_VENDOR = 'vendor';
    public const PART_ASSETS = 'assets';

    /** Variant name => the parts it leaves out. */
    public const VARIANTS = [
        'full' => [],
        'no-node' => [self::PART_NODE],
        'no-vendor' => [self::PART_VENDOR],
        'no-assets' => [self::PART_ASSETS],
        'no-node-vendor' => [self::PART_NODE, self::PART_VENDOR],
        'no-node-assets' => [self::PART_NODE, self::PART_ASSETS],
        'no-vendor-assets' => [self::PART_VENDOR, self::PART_ASSETS],
        'minimal' => [self::PART_NODE, self::PART_VENDOR, self::PART_ASSETS],
    ];

    public const VARIANT_LABELS = [
        'full' => 'Everything (vendor + node_modules + resources js/css)',
        'no-node' => 'Without node_modules',
        'no-vendor' => 'Without vendor',
        'no-assets' => 'Without resources js/css (prebuilt public assets only)',
        'no-node-vendor' => 'Without node_modules and vendor',
        'no-node-assets' => 'Without node_modules and resources js/css',
        'no-vendor-assets' => 'Without vendor and resources js/css',
        'minimal' => 'Without node_modules, vendor and resources js/css',
    ];

    /** Never packaged. */
    private const EXCLUDE = [
        '.git', '.svn', '.idea', '.vscode', '.fleet', '.claude',
        '.phpunit.result.cache', '.phpunit.cache',
        'node_modules/.cache', 'node_modules/.vite',
        'public/hot', 'public/storage',
    ];

    /**
     * Kept as empty folders: only their .gitignore files go in. storage/ is
     * runtime data (logs, sessions, uploads, backups), never part of a release.
     */
    private const CONTENT_ONLY = [
        'storage',
        'bootstrap/cache',
    ];

    private const JUNK_FILES = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];

    private array $exclude;
    private array $assetPaths;

    public function __construct(private readonly string $root, array $extraExclude = [], array $assetPaths = [])
    {
        $this->exclude = array_values(array_filter(array_map([$this, 'normalize'], [...self::EXCLUDE, ...$extraExclude])));
        $this->assetPaths = array_values(array_filter(array_map([$this, 'normalize'], $assetPaths)));
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
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            /** @var SplFileInfo $file */
            $rel = $this->relative($root, $file->getPathname());
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
     * @param  list<array{abs: string, rel: string, part: ?string, exec: bool}>  $files
     * @param  list<string>  $leaveOut  parts to leave out
     * @param  array<string, string>  $extraFiles  zip path => absolute source file
     * @param  array<string, string>  $extraStrings  zip path => contents
     * @return array{ok: bool, files?: int, message?: string}
     */
    public function build(string $zipPath, array $files, array $leaveOut, array $extraFiles = [], array $extraStrings = [], ?callable $progress = null, bool $retry = true): array
    {
        $tmp = $zipPath . '.part';
        @unlink($tmp);

        $zip = new ZipArchive();
        if (($code = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
            return ['ok' => false, 'message' => "Could not create '{$tmp}' (ZipArchive error {$code})."];
        }

        $count = 0;
        foreach ($files as $file) {
            if ($file['part'] !== null && in_array($file['part'], $leaveOut, true)) {
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

        foreach (self::CONTENT_ONLY as $dir) {
            if (is_dir($this->root . '/' . $dir)) {
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
                return $this->build($zipPath, $present, $leaveOut, $extraFiles, $extraStrings, $progress, false);
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

        foreach (self::CONTENT_ONLY as $dir) {
            if (str_starts_with($rel, $dir . '/')) {
                return $name === '.gitignore';
            }
        }

        return true;
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
        if (str_starts_with($rel, 'node_modules/')) {
            return self::PART_NODE;
        }
        if (str_starts_with($rel, 'vendor/')) {
            return self::PART_VENDOR;
        }
        foreach ($this->assetPaths as $path) {
            if ($rel === $path || str_starts_with($rel, $path . '/')) {
                return self::PART_ASSETS;
            }
        }

        return null;
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
