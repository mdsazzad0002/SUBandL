<?php

namespace SUBandL\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use SUBandL\Support\BackgroundArtisan;
use SUBandL\Support\Platform;

/**
 * Poor-man's cron: fires the license, update and backup checks from
 * web traffic (page visits), as detached processes, so they happen even on
 * hosts with no system cron. These TTLs are only how often a spawn is
 * attempted — every command self-throttles its real provider work. Nothing
 * is spawned on the visit that starts the clock, only once a TTL has passed.
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
        if (! config('subandl.middleware.web_scheduler', true)
            || ! $request->isMethod('GET')
            || $request->routeIs('subandl.asset')) {
            return $next($request);
        }

        foreach (self::CHECKS as $command => $ttlSeconds) {
            $key = "subandl:scheduler:{$command}:due";
            $due = Cache::get($key);

            // Never on arrival: a first visit (fresh install, cleared cache, just
            // updated) only starts the clock, so opening the site never sets off
            // license/update/backup work at once — it runs on a later visit.
            if (! is_int($due)) {
                Cache::add($key, time() + $ttlSeconds);

                continue;
            }

            if ($due <= time() && Cache::add("subandl:scheduler:{$command}:claim", true, 60)) {
                Cache::forever($key, time() + $ttlSeconds);
                if (! BackgroundArtisan::dispatch($command)) {
                    $this->runAfterResponse($command);
                }
            }
        }

        return $next($request);
    }

    /**
     * No process could be spawned (exec and popen disabled). Where the server
     * can hand the page to the browser first — PHP-FPM, LiteSpeed — run the
     * check in this process once the response is out, so the visitor never
     * waits for it. Elsewhere (mod_php) the next system cron run picks it up.
     */
    private function runAfterResponse(string $command): void
    {
        if (! Platform::functionEnabled('fastcgi_finish_request') && ! Platform::functionEnabled('litespeed_finish_request')) {
            return;
        }

        app()->terminating(function () use ($command) {
            Platform::allowLongRunning();
            try {
                Artisan::call($command);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
