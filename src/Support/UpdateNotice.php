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
            'version_title' => $check['version_title'] ?? null,
            'locked' => (bool) ($check['locked'] ?? false),
            'monthly_fee' => $check['monthly_fee'] ?? null,
            'pending' => array_map(fn ($p) => ['version' => $p['latest_version'], 'title' => $p['version_title'] ?? null], $check['pending'] ?? []),
            'checked_at' => now()->toDateTimeString(),
        ]);

        // A new version: fetch its package in the background now, so
        // installing it later does not have to wait for the download.
        app(\SUBandL\Update\UpdateApplier::class)->schedulePrefetch($check);
    }

    /**
     * @return array{available: bool, latest_version: ?string, changelog: mixed, force_update: bool, version_title: ?string, locked: bool, monthly_fee: mixed, checked_at: ?string}
     */
    public static function get(): array
    {
        $notice = Cache::get(self::KEY) ?: [];
        $latest = $notice['latest_version'] ?? null;
        $current = (string) config('subandl.version', '1.0.0');

        $available = $latest && version_compare((string) $latest, $current, '>');
        $pending = $available ? self::stillPending($notice['pending'] ?? [], (string) $latest, $current) : [];

        return [
            'available' => $available,
            'latest_version' => $available ? $latest : null,
            'changelog' => $available ? ($notice['changelog'] ?? null) : null,
            'force_update' => $available && ($notice['force_update'] ?? false),
            'version_title' => $available ? ($notice['version_title'] ?? null) : null,
            'locked' => $available && ($notice['locked'] ?? false),
            'monthly_fee' => $notice['monthly_fee'] ?? null,
            // The newer versions still to install (oldest first) and the last of them.
            'pending_versions' => $pending,
            'target_version' => $available ? (end($pending) ?: $latest) : null,
            'checked_at' => $notice['checked_at'] ?? null,
        ];
    }

    /** @return list<string> versions newer than $current, oldest first; at least the announced one */
    private static function stillPending(array $pending, string $latest, string $current): array
    {
        $versions = [];
        foreach ($pending as $p) {
            $v = (string) ($p['version'] ?? '');
            if ($v !== '' && version_compare($v, $current, '>')) {
                $versions[] = $v;
            }
        }
        if (! in_array($latest, $versions, true)) {
            $versions[] = $latest;
        }
        usort($versions, 'version_compare');

        return array_values(array_unique($versions));
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
