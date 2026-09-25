<?php

namespace SUBandL\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The last "is there a newer version?" answer from the provider, kept so the
 * widget can announce an update on every page without asking the provider
 * each time. Every update check (button, background command, widget) writes
 * it; a successful update clears it.
 *
 * Reads compare against the running version, so a notice for a version that
 * is already installed (or older) never shows, even if nothing cleared it.
 */
class UpdateNotice
{
    private const KEY = 'subandl:update-notice';

    public static function record(array $check): void
    {
        if (! ($check['ok'] ?? false)) {
            return;
        }

        Cache::forever(self::KEY, [
            'latest_version' => $check['latest_version'] ?? null,
            'changelog' => $check['changelog'] ?? null,
            'force_update' => (bool) ($check['force_update'] ?? false),
            'checked_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * @return array{available: bool, latest_version: ?string, changelog: mixed, force_update: bool, checked_at: ?string}
     */
    public static function get(): array
    {
        $notice = Cache::get(self::KEY) ?: [];
        $latest = $notice['latest_version'] ?? null;
        $current = (string) config('subandl.version', '1.0.0');

        $available = $latest && version_compare((string) $latest, $current, '>');

        return [
            'available' => $available,
            'latest_version' => $available ? $latest : null,
            'changelog' => $available ? ($notice['changelog'] ?? null) : null,
            'force_update' => $available && ($notice['force_update'] ?? false),
            'checked_at' => $notice['checked_at'] ?? null,
        ];
    }

    public static function checkedAt(): ?string
    {
        return (Cache::get(self::KEY) ?: [])['checked_at'] ?? null;
    }

    public static function clear(): void
    {
        Cache::forget(self::KEY);
    }
}
