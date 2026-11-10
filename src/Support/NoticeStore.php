<?php

namespace SUBandL\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Notices from the provider, shown by the widget (a popup once per user, and
 * the Notices tab). The provider sends the full current list as `notices` in
 * every /api/verify-license and /api/check-update answer; each answer that
 * carries the key replaces what is stored, so a notice removed on the portal
 * disappears with the next check. Answers without the key (older providers,
 * error answers) leave the stored list alone.
 *
 * Reading never contacts the provider, and drops notices outside their
 * start/end window locally, so a notice ends on time even between checks.
 */
class NoticeStore
{
    private const KEY = 'subandl:notices';

    private const TYPES = ['info', 'success', 'warning', 'danger'];

    /** @param mixed $json the provider's decoded answer */
    public static function recordFrom(mixed $json): void
    {
        if (! is_array($json) || ! array_key_exists('notices', $json) || ! is_array($json['notices'])) {
            return;
        }

        try {
            Cache::forever(self::KEY, [
                'items' => self::clean($json['notices']),
                'received_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The notices to show now, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function visible(): array
    {
        try {
            $stored = Cache::get(self::KEY);
        } catch (\Throwable $e) {
            return [];
        }

        $items = is_array($stored['items'] ?? null) ? $stored['items'] : [];
        $now = time();

        return array_values(array_filter($items, function (array $n) use ($now) {
            $starts = $n['starts_at'] ? strtotime($n['starts_at']) : false;
            $ends = $n['ends_at'] ? strtotime($n['ends_at']) : false;

            return ($starts === false || $starts <= $now) && ($ends === false || $ends >= $now);
        }));
    }

    public static function clear(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * Only well-formed notices, with only the fields the widget uses. A link
     * must be http(s), so a notice can never carry a javascript: URL.
     *
     * @param  array<mixed>  $notices
     * @return array<int, array<string, mixed>>
     */
    private static function clean(array $notices): array
    {
        $clean = [];

        foreach (array_slice($notices, 0, 50) as $n) {
            if (! is_array($n) || ! isset($n['id']) || ! is_scalar($n['id']) || trim((string) ($n['title'] ?? '')) === '') {
                continue;
            }

            $link = is_string($n['link_url'] ?? null) && preg_match('#^https?://#i', $n['link_url']) ? $n['link_url'] : null;
            $type = in_array($n['type'] ?? null, self::TYPES, true) ? $n['type'] : 'info';

            $clean[] = [
                'id' => (string) $n['id'],
                // Changes when the notice is edited, so it shows as new again.
                'key' => $n['id'] . ':' . (is_scalar($n['revision'] ?? null) ? $n['revision'] : '0'),
                'title' => mb_substr(trim((string) $n['title']), 0, 255),
                'body' => is_string($n['body'] ?? null) ? mb_substr($n['body'], 0, 5000) : null,
                'type' => $type,
                'popup' => (bool) ($n['popup'] ?? false),
                'link_url' => $link,
                'link_label' => $link ? (mb_substr(trim((string) ($n['link_label'] ?? '')), 0, 100) ?: 'Open link') : null,
                'starts_at' => is_string($n['starts_at'] ?? null) ? $n['starts_at'] : null,
                'ends_at' => is_string($n['ends_at'] ?? null) ? $n['ends_at'] : null,
                'published_at' => is_string($n['published_at'] ?? null) ? $n['published_at'] : null,
            ];
        }

        return $clean;
    }
}
