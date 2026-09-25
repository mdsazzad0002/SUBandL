<?php

namespace SUBandL\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use SUBandL\Support\BackgroundArtisan;

/**
 * Poor-man's cron: fires the license, update and backup checks from
 * web traffic (page visits), as detached processes, so they happen even on
 * hosts with no system cron. These TTLs are only how often a spawn is
 * attempted — every command self-throttles its real provider work.
 */
class RunScheduledTasks
{
    private const CHECKS = [
        'subandl:license-check' => 1800,
        'subandl:update-check' => 3600,
        'subandl:backup' => 900,
    ];

    public function handle(Request $request, Closure $next)
    {
        // Any page visit counts (signed in or not): the daily backup must go
        // out as soon as someone opens the site. Each spawn is throttled here
        // and every command also throttles itself.
        if (! config('subandl.middleware.web_scheduler', true)
            || ! $request->isMethod('GET')
            || $request->routeIs('subandl.asset')) {
            return $next($request);
        }

        foreach (self::CHECKS as $command => $ttlSeconds) {
            if (Cache::add("subandl:scheduler:{$command}", true, $ttlSeconds)) {
                BackgroundArtisan::dispatch($command);
            }
        }

        return $next($request);
    }
}
