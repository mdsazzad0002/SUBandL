<?php

namespace SUBandL\License;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SUBandL\Models\ActivityLog;

/**
 * Whether the provider is currently reachable, used to gate license/update/backup
 * tasks so they don't do real work only to fail on an unreachable server.
 *
 * The reachability check itself runs in the BROWSER (some hosts restrict outbound
 * traffic from the server while the admin's browser reaches the provider fine, or the
 * reverse) and is reported to record() via POST /license/health-report.
 *
 * Right before work that changes something (applying an update, uploading a
 * backup) probe() asks the provider's /api/ping from the SERVER, because that
 * work is done by the server. A failed probe — or a failed live call — starts
 * a back-off of config('subandl.health_retry_minutes') (min 30): automatic
 * tasks wait it out and then retry on their own. Explicit user actions may
 * still try, and a successful probe ends the back-off early.
 */
class ServerHealth
{
    public const CACHE_MINUTES = 30;

    private const STATUS_KEY = 'subandl:server-health:status';
    private const CHECKED_AT_KEY = 'subandl:server-health:checked-at';
    private const CLAIM_KEY = 'subandl:server-health:claim';
    private const BACKOFF_KEY = 'subandl:server-health:backoff-until';

    /**
     * Fails OPEN (true) when nothing has been reported recently, so scheduled tasks
     * still get a chance to run instead of being blocked by the absence of a check.
     */
    public function isHealthy(): bool
    {
        if ($this->backoffUntil()) {
            return false;
        }

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

    /**
     * Hands the reachability check to ONE browser once per CACHE_MINUTES, so page
     * loads never ping the provider — at most one ping per window, whatever the
     * number of users, tabs and refreshes.
     */
    public function claimBrowserCheck(): bool
    {
        $last = $this->lastCheckedAt();

        if ($last && $last->gt(now()->subMinutes(self::CACHE_MINUTES - 5))) {
            return false;
        }

        return Cache::add(self::CLAIM_KEY, true, now()->addMinutes(5));
    }

    public static function retryMinutes(): int
    {
        return max(30, (int) config('subandl.health_retry_minutes', 30));
    }

    /** The provider failed from the server: automatic tasks wait before trying again. */
    public function backOff(): Carbon
    {
        $until = now()->addMinutes(self::retryMinutes());
        Cache::put(self::BACKOFF_KEY, $until, $until);
        $this->record(false);

        return $until;
    }

    public function backoffUntil(): ?Carbon
    {
        $until = Cache::get(self::BACKOFF_KEY);

        return $until instanceof Carbon && $until->isFuture() ? $until : null;
    }

    /**
     * Live server-side reachability check of the provider (GET /api/ping),
     * tried config('subandl.health_attempts') times (min 2) a few seconds
     * apart before it counts as down. Down → back-off + activity log entry.
     *
     * @return array{ok: bool, message: string, retry_at: ?string, attempts: int}
     */
    public function probe(?int $attempts = null, ?int $timeout = null, string $task = 'live check'): array
    {
        $url = rtrim((string) config('subandl.provider_url'), '/') . '/api/ping';
        $attempts ??= max(2, (int) config('subandl.health_attempts', 2));
        $timeout ??= (int) config('subandl.health_timeout', 8);
        $wasDown = $this->backoffUntil() !== null;
        $reason = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()->timeout($timeout)->get($url);
                if ($response->successful()) {
                    Cache::forget(self::BACKOFF_KEY);
                    $this->record(true);
                    $this->rememberProbe(true, 'Provider server is reachable.');

                    if ($wasDown || $attempt > 1) {
                        ActivityLog::record('health', true, $attempt > 1
                            ? "Provider reachable on attempt {$attempt} (before {$task})."
                            : "Provider reachable again (before {$task}).");
                    }

                    return ['ok' => true, 'message' => 'Provider server is reachable.', 'retry_at' => null, 'attempts' => $attempt];
                }
                $reason = 'HTTP ' . $response->status();
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
            }

            if ($attempt < $attempts) {
                sleep((int) config('subandl.health_retry_delay_seconds', 3));
            }
        }

        $until = $this->backOff();
        $message = 'The provider server is not reachable right now. It will be tried again automatically after '
            . $until->format('H:i') . '.';

        $this->rememberProbe(false, $message);
        ActivityLog::record('health', false, "Provider unreachable after {$attempts} attempts ({$reason}); {$task} skipped. Next try after {$until->format('Y-m-d H:i')}.", [
            'task' => $task,
            'reason' => $reason,
        ]);

        return ['ok' => false, 'message' => $message, 'retry_at' => $until->toDateTimeString(), 'attempts' => $attempts];
    }

    /**
     * The gate every live call passes first. Automatic work respects the
     * back-off (returns false without contacting anything while it runs);
     * an explicit user action ($respectBackoff = false) probes anyway.
     */
    public function gate(string $task, bool $respectBackoff = true): array
    {
        if ($respectBackoff && ($until = $this->backoffUntil())) {
            return [
                'ok' => false,
                'message' => 'The provider server was unreachable; next try after ' . $until->format('H:i') . '.',
                'retry_at' => $until->toDateTimeString(),
                'attempts' => 0,
            ];
        }

        return $this->probe(task: $task);
    }

    private function rememberProbe(bool $ok, string $message): void
    {
        Cache::forever('subandl:server-health:last-probe', [
            'ok' => $ok,
            'message' => $message,
            'at' => now()->toDateTimeString(),
        ]);
    }

    /** @return array{ok: bool, message: string, at: string}|null */
    public function lastProbe(): ?array
    {
        return Cache::get('subandl:server-health:last-probe');
    }
}
