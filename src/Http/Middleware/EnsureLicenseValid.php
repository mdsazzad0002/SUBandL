<?php

namespace SUBandL\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use SUBandL\License\IdentityWatch;
use SUBandL\License\LicenseVerifier;
use SUBandL\License\ServerHealth;
use SUBandL\Models\LicenseState;

/**
 * Sends authenticated users to the subscription / verification page while the
 * cached license state is unusable. Non-destructive: it only gates access, and
 * it only ever reads the cached state — a page load never waits on the
 * license server. Keeping that cache fresh is the scheduler's job.
 */
class EnsureLicenseValid
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('subandl.middleware.enforce_license', true)) {
            return $next($request);
        }

        $this->verifyOnIdentityChange($request);

        // The widget's static files and its state endpoint — a blocked client
        // must still see what to pay. A published config's allowed_when_invalid
        // predates them, so they are exempted here rather than in the config.
        if ($request->routeIs('subandl.asset', 'subandl.widget')) {
            return $next($request);
        }

        foreach ((array) config('subandl.allowed_when_invalid', []) as $allowed) {
            if ($request->is($allowed)) {
                return $next($request);
            }
        }

        // An invalid license must never block the login page itself.
        if (! $request->user()) {
            return $next($request);
        }

        $state = LicenseState::current();

        if (! $state->needsSubscriptionRedirect()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => false,
                'reason' => $state->status,
                'message' => 'License verification failed. Please contact your provider.',
            ], 403);
        }

        return redirect()->route($state->redirectRouteName());
    }

    /**
     * The one exception to "page loads never contact the provider": when the
     * site is opened on a domain, or runs on a device, other than the one in
     * the database, the license is verified live right now, so a copied or
     * moved install is caught (or a legitimate move confirmed) immediately.
     * A first visit only records the baseline — no live call. The call uses a
     * short timeout; if the provider is down the page carries on with the
     * stored state and the check is retried after the health back-off.
     */
    private function verifyOnIdentityChange(Request $request): void
    {
        if (! config('subandl.verify_on_identity_change', true)
            || $request->routeIs('subandl.asset')
            || ! app(IdentityWatch::class)->changed($request)
            || app(ServerHealth::class)->backoffUntil()) {
            return;
        }

        $lock = Cache::lock('subandl:identity-verify', 30);

        if (! $lock->get()) {
            return;
        }

        // Short timeouts on a page load: the health ping (2 attempts) and the
        // license call together stay within seconds even with the provider down.
        $timeout = config('subandl.http_timeout', 15);
        $short = (int) config('subandl.identity_check_timeout', 5);
        $saved = [
            'subandl.http_timeout' => $timeout,
            'subandl.health_timeout' => config('subandl.health_timeout', 8),
            'subandl.health_retry_delay_seconds' => config('subandl.health_retry_delay_seconds', 3),
        ];
        config([
            'subandl.http_timeout' => min((int) $timeout, $short),
            'subandl.health_timeout' => min(3, $short),
            'subandl.health_retry_delay_seconds' => 1,
        ]);

        try {
            app(LicenseVerifier::class)->refresh(force: true);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            config($saved);
            $lock->release();
        }
    }
}
