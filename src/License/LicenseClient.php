<?php

namespace SUBandL\License;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
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
            $response = $this->http()->post($this->url('verify-license'), $this->identityPayload());
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
            'update_support_expires_at' => $json['maintenance_end_date'] ?? null,
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
            $response = $this->http()->post($this->url('check-update'), $this->identityPayload() + [
                'version' => $currentVersion,
            ]);
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
        if ($latestVersion && version_compare((string) $latestVersion, $currentVersion, '>')) {
            $updateAvailable = true;
        }

        return [
            'ok' => (bool) ($json['license_valid'] ?? false),
            'update_available' => $updateAvailable,
            'latest_version' => $latestVersion,
            'download_url' => $json['download_url'] ?? null,
            'checksum' => $json['checksum'] ?? $json['sha256'] ?? null,
            'force_update' => (bool) ($json['force_update'] ?? false),
            'changelog' => $json['changelog'] ?? null,
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
            $response = $this->http()->post($this->url($endpoint), [
                'software' => config('subandl.software_slug'),
                'license_key' => LicenseState::resolveLicenseKey(),
            ]);
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
            'domain' => request()?->getHost(),
        ];
    }

    public function url(string $endpoint): string
    {
        return rtrim((string) config('subandl.provider_url'), '/') . '/api/' . ltrim($endpoint, '/');
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
            'access_denied', 'device_mismatch' => 'tampered',
            default => 'invalid',
        };
    }
}
