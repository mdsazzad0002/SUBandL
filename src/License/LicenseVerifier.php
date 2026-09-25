<?php

namespace SUBandL\License;

use Illuminate\Support\Facades\Log;
use SUBandL\Events\LicenseVerified;
use SUBandL\Models\LicenseState;

/**
 * Single place that performs a live verification against the provider and
 * persists the result into LicenseState. Called by the background check and
 * by explicit user actions (Save License, Refresh) that pass force: true.
 *
 * Automatic (non-forced) calls self-throttle to config('subandl.verify_cache_minutes')
 * and skip entirely when the browser's last ping says the provider is
 * unreachable. A forced call always attempts the live check.
 */
class LicenseVerifier
{
    public function __construct(
        private readonly LicenseClient $client,
        private readonly ServerHealth $health,
    ) {
    }

    public function refresh(bool $force = false): LicenseState
    {
        $state = LicenseState::current();

        if (! $force) {
            if (! $this->health->isHealthy()) {
                Log::info('SUBandL: license check skipped, provider reported unreachable by browser ping.');

                return $state;
            }

            $minInterval = (int) config('subandl.verify_cache_minutes', 720);
            if ($state->last_verified_at && $state->last_verified_at->gt(now()->subMinutes($minInterval))) {
                return $state;
            }
        }

        $result = $this->client->verify();

        // A network hiccup or provider outage must never block the app or overwrite a
        // previously-valid cached state — only a genuine verdict from the provider should.
        if (($result['status'] ?? null) === 'unreachable') {
            Log::info('SUBandL: license check skipped, provider unreachable.');

            return $state;
        }

        // Record which domain first verified this license, purely for display/audit.
        // Enforcement is the provider's device_id binding, which the portal can clear
        // to allow a legitimate migration.
        if (empty($state->domain) && ! app()->runningInConsole() && ($currentHost = request()->getHost())) {
            $state->domain = $currentHost;
        }

        $state->status = $result['ok'] ? ($result['status'] ?? 'active') : ($result['status'] ?? 'invalid');
        $state->subscription_type = $result['subscription_type'] ?? $state->subscription_type;
        $state->expires_at = $result['expires_at'] ?? $state->expires_at;
        $state->update_support_expires_at = $result['update_support_expires_at'] ?? $state->update_support_expires_at;
        $state->client_name = $result['client_name'] ?? $state->client_name;
        $state->client_email = $result['client_email'] ?? $state->client_email;
        $state->grace_days = $result['grace_days'] ?? $state->grace_days;
        $state->in_grace_period = (bool) ($result['in_grace_period'] ?? false);
        $state->grace_ends_at = $result['grace_ends_at'] ?? $state->grace_ends_at;
        $state->due_amount = $result['due_amount'] ?? $state->due_amount;
        $state->monthly_fee = $result['monthly_fee'] ?? $state->monthly_fee;
        $state->payment_info = $result['payment_info'] ?? $state->payment_info;
        $state->code_integrity_ok = $result['code_integrity_ok'] ?? true;
        $state->last_verified_at = now();
        $state->last_verification_response = $result['raw'] ?? null;
        $state->last_verification_error = $result['ok'] ? null : ($result['message'] ?? 'Verification failed.');
        $state->save();

        Log::info('SUBandL: license check completed.', [
            'status' => $state->status,
            'code_integrity_ok' => $state->code_integrity_ok,
        ]);

        event(new LicenseVerified($state, $result));

        return $state;
    }
}
