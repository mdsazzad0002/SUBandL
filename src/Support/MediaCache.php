<?php

namespace SUBandL\Support;

use Illuminate\Support\Facades\Log;

/**
 * Local copies of the images the provider lists for this software (the cover
 * image, video thumbnails). They are downloaded by the license check — never
 * on a page load — and served from /subandl/media with a year-long browser
 * cache, so showing them costs the provider nothing after the first download.
 *
 * Files are named by the sha1 of their source URL; a changed image on the
 * provider comes with a new URL and therefore a new file.
 */
class MediaCache
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** The local URL of a cached copy, or null while it isn't downloaded. */
    public static function url(?string $remote): ?string
    {
        $file = $remote ? self::find($remote) : null;

        return $file ? url('/subandl/media/' . $file) : null;
    }

    /** Absolute path of a cached file by its served name, or null. */
    public static function path(string $file): ?string
    {
        $path = self::dir() . DIRECTORY_SEPARATOR . basename($file);

        return Platform::isFile($path) ? $path : null;
    }

    /**
     * Downloads what is missing and removes what is no longer listed.
     *
     * @param  array<int, string>  $urls
     */
    public static function sync(array $urls): void
    {
        $dir = self::dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }

        $keep = [];
        foreach (array_unique(array_filter($urls)) as $url) {
            $file = self::find($url) ?? self::download($url);
            if ($file) {
                $keep[] = $file;
            }
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (! in_array(basename($path), $keep, true)) {
                @unlink($path);
            }
        }
    }

    private static function download(string $url): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $response = HttpClient::request()->timeout(15)->get($url);
        } catch (\Throwable $e) {
            Log::info('SUBandL: media download failed.', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();
        if (! $response->successful() || ! isset(self::TYPES[$type]) || $body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $file = sha1($url) . '.' . self::TYPES[$type];

        return @file_put_contents(self::dir() . DIRECTORY_SEPARATOR . $file, $body) === false ? null : $file;
    }

    private static function find(string $url): ?string
    {
        foreach (self::TYPES as $ext) {
            $file = sha1($url) . '.' . $ext;
            if (self::path($file)) {
                return $file;
            }
        }

        return null;
    }

    private static function dir(): string
    {
        return rtrim((string) config('subandl.media_path', storage_path('app/subandl-media')), '/\\');
    }
}
