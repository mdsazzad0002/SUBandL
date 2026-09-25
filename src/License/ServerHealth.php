<?php

namespace SUBandL\License;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the provider is currently reachable, used to gate license/update/backup
 * tasks so they don't do real work only to fail on an unreachable server.
 *
 * The reachability check itself runs in the BROWSER (some hosts restrict outbound
 * traffic from the server while the admin's browser reaches the provider fine, or the
 * reverse) and is reported to record() via POST /license/health-report. This class
 * never contacts the provider itself.
 */
class ServerHealth
{
    public const CACHE_MINUTES = 30;

    private const STATUS_KEY = 'subandl:server-health:status';
    private const CHECKED_AT_KEY = 'subandl:server-health:checked-at';

    /**
     * Fails OPEN (true) when nothing has been reported recently, so scheduled tasks
     * still get a chance to run instead of being blocked by the absence of a check.
     */
    public function isHealthy(): bool
    {
        $cached = Cache::get(self::STATUS_KEY);

        return $cached === null ? true : (bool) $cached;
    }

    public function record(bool $healthy): void
    {
        Cache::put(self::STATUS_KEY, $healthy, now()->addMinutes(self::CACHE_MINUTES));
        Cache::put(self::CHECKED_AT_KEY, now(), now()->addMinutes(self::CACHE_MINUTES));
    }

    public function lastCheckedAt(): ?Carbon
    {
        return Cache::get(self::CHECKED_AT_KEY);
    }
}
