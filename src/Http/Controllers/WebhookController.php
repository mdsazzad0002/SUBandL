<?php

namespace SUBandL\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use SUBandL\License\LicenseVerifier;
use SUBandL\Models\ActivityLog;
use SUBandL\Models\LicenseState;

/**
 * The provider calls this when it changes something about the license —
 * a payment, a due, a block — so the app re-verifies at once instead of
 * waiting for its next scheduled check.
 *
 * The request carries no license data: it only says "check now", and the
 * app then asks the provider itself (LicenseVerifier). So a forged call can
 * at most trigger an extra verification; the signature (HMAC-SHA256 of
 * "timestamp.body" keyed with the license key) and the throttle stop even that.
 */
class WebhookController extends Controller
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function handle(Request $request, LicenseVerifier $verifier): JsonResponse
    {
        $licenseKey = (string) LicenseState::resolveLicenseKey();
        $timestamp = (string) $request->header('X-SUBandL-Timestamp', '');
        $signature = (string) $request->header('X-SUBandL-Signature', '');

        if ($licenseKey === '' || $timestamp === '' || $signature === ''
            || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return response()->json(['ok' => false, 'message' => 'Invalid webhook request.'], 401);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $licenseKey);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['ok' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        // Several changes in a row (invoice + payment + status) need one check.
        if (! Cache::add('subandl:webhook-refresh', true, (int) config('subandl.webhook.throttle_seconds', 10))) {
            return response()->json(['ok' => true, 'message' => 'Refresh already ran moments ago.']);
        }

        $event = (string) $request->input('event', 'license.changed');
        $state = $verifier->refresh(force: true);

        ActivityLog::record('license', $state->last_verification_error === null, "Provider webhook ({$event}): license re-verified, status {$state->status}.");

        return response()->json([
            'ok' => true,
            'status' => $state->status,
            'due_amount' => $state->due_amount,
            'verified_at' => $state->last_verified_at?->toIso8601String(),
        ]);
    }
}
