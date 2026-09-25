<?php

namespace SUBandL\Support;

/**
 * Inlines the shared UI core (resources/js/subandl) into the Blade pages, so
 * Blade, Vue and React all run the exact same JS and CSS.
 */
class Assets
{
    private const FILES = ['subandl.js', 'subandl.css'];

    public static function inline(string $file): string
    {
        if (! in_array($file, self::FILES, true)) {
            return '';
        }

        return (string) @file_get_contents(dirname(__DIR__, 2) . '/resources/js/subandl/' . $file);
    }
}
