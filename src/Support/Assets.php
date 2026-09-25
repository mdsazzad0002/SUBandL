<?php

namespace SUBandL\Support;

/**
 * Serves the shared UI core (resources/js/subandl): inlined into the Blade
 * pages, and served as files to the global widget, so Blade, Vue, React and
 * the widget all run the exact same JS and CSS.
 */
class Assets
{
    private const FILES = ['subandl.js', 'subandl.css', 'widget.js', 'widget.css'];

    public static function inline(string $file): string
    {
        if (! in_array($file, self::FILES, true)) {
            return '';
        }

        return (string) @file_get_contents(self::path($file));
    }

    /** Changes whenever any asset changes — the cache-busting ?v= value. */
    public static function version(): string
    {
        $stamp = '';
        foreach (self::FILES as $file) {
            $stamp .= $file . @filemtime(self::path($file)) . @filesize(self::path($file));
        }

        return substr(md5($stamp), 0, 12);
    }

    private static function path(string $file): string
    {
        return dirname(__DIR__, 2) . '/resources/js/subandl/' . $file;
    }
}
