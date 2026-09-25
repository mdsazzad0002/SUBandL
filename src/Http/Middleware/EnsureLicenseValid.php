<?php

namespace SUBandL\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        // The widget's static files; a published config's allowed_when_invalid
        // predates them, so they are exempted here rather than in the config.
        if ($request->routeIs('subandl.asset')) {
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
}
