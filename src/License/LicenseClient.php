<?php

namespace SUBandL\License;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SUBandL\Models\ActivityLog;
use SUBandL\Models\LicenseState;

/**
 * Talks to the provider's licensing API. Endpoints and payload/response shapes
 * must match the provider's /api/verify-license, /api/check-update and
 * /api/{module}-status controllers.
 */
class LicenseClient
{
    public function verify(): array
    {
        try {
            $response = $this->send('verify-license', $this->identityPayload(), 'license');
        } catch (ConnectionException $exception) {
            // Distinct from a genuine "invalid" verdict — LicenseVerifier checks for
            // this status so a network hiccup never overwrites a valid cached state.
            return [
                'ok' => false,
                'status' => 'unreachable',
                'message' => 'Unable to reach license server: ' . $exception->getMessage(),
            ];
        }

        $json = $response->json() ?? [];

        // The provider returns 403/404 with a JSON body for known failure reasons
        // (invalid, expired, device mismatch) — those are verdicts to parse, not
        // transport failures.
        if ($json === [] && ! $response->successful()) {
            // A 5xx with no JSON body is the host being down (a proxy's 502/503/504
            // page), never an 'invalid' verdict.
            if ($response->serverError()) {
                return [
                    'ok' => false,
                    'status' => 'unreachable',
                    'message' => 'License server unavailable (HTTP ' . $response->status() . ').',
                ];
            }

            return [
                'ok' => false,
                'status' => 'invalid',
                'message' => 'License server returned an error (HTTP ' . $response->status() . ').',
            ];
        }

        $licenseValid = (bool) ($json['license_valid'] ?? false);
        $apiStatus = $json['status'] ?? 'invalid_license';

        return [
            'ok' => $licenseValid && $apiStatus === 'ok',
            'status' => $this->mapStatus($apiStatus),
            'subscription_type' => $json['subscription_type'] ?? null,
            'expires_at' => $json['expires_at'] ?? null,
            // Only lifetime plans have an update-subscription date; monthly
            // plans get updates for as long as the license is active.
            'update_support_expires_at' => $json['billing']['updates_until'] ?? $json['maintenance_end_date'] ?? null,
            'updates_included' => $json['updates_included'] ?? null,
            'billing' => $json['billing'] ?? null,
            'client_name' => $json['client_name'] ?? null,
            'client_email' => $json['client_email'] ?? null,
            'grace_days' => $json['grace_days'] ?? null,
            'max_branches' => $json['max_branches'] ?? null,
            'in_grace_period' => $json['in_grace_period'] ?? false,
            'grace_ends_at' => $json['grace_ends_at'] ?? null,
            'due_amount' => $json['due_amount'] ?? null,
            'monthly_fee' => $json['monthly_fee'] ?? null,
            'payment_info' => $json['payment_info'] ?? null,
            'code_integrity_ok' => true,
            'message' => $json['message'] ?? null,
            'raw' => $json,
        ];
    }

    public function checkForUpdate(?string $currentVersion = null): array
    {
        $currentVersion ??= (string) config('subandl.version', '1.0.0');

        try {
            $response = $this->send('check-update', $this->identityPayload() + [
                'version' => $currentVersion,
            ], 'update-check');
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'message' => 'Unable to reach update server: ' . $exception->getMessage()];
        }

        $json = $response->json() ?? [];

        if ($json === [] && ! $response->successful()) {
            return ['ok' => false, 'message' => 'Update server returned an error (HTTP ' . $response->status() . ').'];
        }

        $latestVersion = $json['latest_version'] ?? null;

        // The provider's `update_available` flag has been observed out of sync with
        // `latest_version`, so fall back to a direct version comparison.
        $updateAvailable = (bool) ($json['update_available'] ?? false);
        $locked = (bool) ($json['update_locked'] ?? false);
        if ($latestVersion && version_compare((string) $latestVersion, $currentVersion, '>')) {
            $updateAvailable = true;
        }

        return [
            'ok' => (bool) ($json['license_valid'] ?? false),
            'update_available' => $updateAvailable,
            'latest_version' => $latestVersion,
            'download_url' => $locked ? null : ($json['download_url'] ?? null),
            'checksum' => $json['checksum'] ?? $json['sha256'] ?? null,
            'force_update' => (bool) ($json['force_update'] ?? false),
            'changelog' => $json['changelog'] ?? null,
            'version_title' => $json['version_title'] ?? null,
            // Lifetime plan without a paid update subscription: the version is
            // announced, but it cannot be downloaded until the fee is paid.
            'locked' => (bool) ($json['update_locked'] ?? false),
            'monthly_fee' => $json['monthly_fee'] ?? null,
            'commands' => $json['commands'] ?? [],
            'message' => $json['message'] ?? null,
            'raw' => $json,
        ];
    }

    /**
     * Live entitlement check for an optional module: POSTs to
     * /api/{module}-status (e.g. checkModule('careflow') → /api/careflow-status).
     * Meant for the moment an admin turns a module ON; turning one off never
     * needs provider approval.
     *
     * @return array{ok: bool, enabled: bool, message: ?string}
     */
    public function checkModule(string $module): array
    {
        $endpoint = str_ends_with($module, '-status') ? $module : "{$module}-status";

        try {
            $response = $this->send($endpoint, [
                'software' => config('subandl.software_slug'),
                'license_key' => LicenseState::resolveLicenseKey(),
            ], 'license');
        } catch (ConnectionException $exception) {
            return [
                'ok' => false,
                'enabled' => false,
                'message' => 'Unable to reach license server: ' . $exception->getMessage(),
            ];
        }

        $json = $response->json() ?? [];

        if ($json === [] && ! $response->successful()) {
            return [
                'ok' => false,
                'enabled' => false,
                'message' => $response->serverError()
                    ? 'License server unavailable (HTTP ' . $response->status() . ').'
                    : 'License server returned an error (HTTP ' . $response->status() . ').',
            ];
        }

        return [
            'ok' => $response->successful() && ($json['status'] ?? null) === 'ok',
            'enabled' => (bool) ($json['enabled'] ?? false),
            'message' => $json['message'] ?? null,
        ];
    }

    /**
     * software + license_key + device binding, shared by every identity-bound call
     * (verify, update check, backup upload).
     */
    public function identityPayload(): array
    {
        return [
            'software' => config('subandl.software_slug'),
            'license_key' => LicenseState::resolveLicenseKey(),
            'device_id' => InstallationIdentity::current()->installation_uuid,
            // A request on a real domain sends that domain. Background (console)
            // checks and visits by IP/localhost send the known domain from the
            // database instead, so the provider's domain binding applies to them too.
            'domain' => IdentityWatch::domain(request()?->getHttpHost()) ?? IdentityWatch::knownDomain(),
            // 2 = understands billing summaries and locked update previews.
            'api_version' => 2,
        ];
    }

    public function url(string $endpoint): string
    {
        return rtrim((string) config('subandl.provider_url'), '/') . '/api/' . ltrim($endpoint, '/');
    }

    /**
     * POSTs to the provider, trying config('subandl.live_attempts') times
     * (min 2) before giving up on a connection error or a 5xx. A definite
     * answer (2xx/4xx) is returned at once. Failed attempts are logged.
     *
     * @throws ConnectionException when every attempt failed to connect
     */
    private function send(string $endpoint, array $payload, string $type): \Illuminate\Http\Client\Response
    {
        $attempts = max(2, (int) config('subandl.live_attempts', 2));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http()->post($this->url($endpoint), $payload);

                if (! $response->serverError() || $attempt >= $attempts) {
                    if ($attempt > 1) {
                        ActivityLog::record($type, $response->successful() || $response->clientError(), "/{$endpoint} answered on attempt {$attempt} (HTTP {$response->status()}).");
                    }

                    return $response;
                }

                $reason = 'HTTP ' . $response->status();
            } catch (ConnectionException $e) {
                if ($attempt >= $attempts) {
                    ActivityLog::record($type, false, "/{$endpoint} failed after {$attempt} attempts: {$e->getMessage()}");
                    throw $e;
                }
                $reason = $e->getMessage();
            }

            ActivityLog::record($type, false, "/{$endpoint} attempt {$attempt} failed ({$reason}); retrying.");
            sleep((int) config('subandl.live_retry_delay_seconds', 2));
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout((int) config('subandl.http_timeout', 15));
    }

    private function mapStatus(string $apiStatus): string
    {
        return match ($apiStatus) {
            'ok' => 'active',
            'expired', 'maintenance_expired' => 'expired',
            'access_denied', 'device_mismatch', 'domain_mismatch' => 'tampered',
            default => 'invalid',
        };
    }
}
