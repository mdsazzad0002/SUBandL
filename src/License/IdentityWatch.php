<?php

namespace SUBandL\License;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Notices the moment this installation shows up somewhere new, so the license
 * is re-verified right away instead of at the next scheduled check:
 *
 *  - domain — the site is opened on a different (real) domain;
 *  - device — the code/database runs on a different machine or folder.
 *
 * The known device and domain live in the database (license_installations:
 * fingerprint + domain), next to the installation identity. Nothing known
 * yet (first visit, fresh install, just upgraded) is NOT a change: the
 * current device/domain simply become the baseline, without contacting the
 * provider — so a first visit never depends on the provider being up.
 *
 * IPs and localhost are not domains (the provider doesn't bind them either),
 * so opening the site by IP never counts as a change.
 */
class IdentityWatch
{
    /** A real domain (lowercase, keeps a non-standard port), or null for IP/localhost. */
    public static function domain(?string $host): ?string
    {
        $host = strtolower(trim((string) $host, ' .'));
        $bare = preg_replace('/:\d+$/', '', $host);

        if ($host === ''
            || $bare === 'localhost'
            || str_ends_with($bare, '.localhost')
            || filter_var(trim($bare, '[]'), FILTER_VALIDATE_IP) !== false
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $host;
    }

    /** This machine + install folder, hashed. */
    public static function device(): string
    {
        return sha1(php_uname('n') . '|' . base_path());
    }

    /**
     * The known real domain — sent as the binding domain by background
     * (console) checks, which have no request of their own.
     */
    public static function knownDomain(): ?string
    {
        return self::domain(InstallationIdentity::current()->domain);
    }

    /**
     * Whether this request comes from a device or domain other than the known
     * one. Records a baseline (and never reports a change) when none is known.
     */
    public function changed(Request $request): bool
    {
        if (! $this->ready()) {
            return false;
        }

        $installation = InstallationIdentity::current();
        $domain = self::domain($request->getHttpHost());
        $knownDomain = self::domain($installation->domain);

        $baseline = [];
        if (! $installation->fingerprint) {
            $baseline['fingerprint'] = self::device();
        }
        if ($knownDomain === null && $domain !== null) {
            $baseline['domain'] = $domain;
        }
        if ($baseline) {
            $installation->forceFill($baseline)->save();
        }

        if ($installation->fingerprint !== self::device()) {
            return true;
        }

        return $domain !== null && $knownDomain !== null && $domain !== $knownDomain;
    }

    /**
     * The provider answered a verification for this device (+ domain): they
     * are the known identity from now on.
     */
    public function remember(?Request $request = null): void
    {
        if (! $this->ready()) {
            return;
        }

        $installation = InstallationIdentity::current();
        $domain = $request ? self::domain($request->getHttpHost()) : null;

        $installation->forceFill(array_filter([
            'fingerprint' => self::device(),
            'domain' => $domain,
        ]))->save();
    }

    /** The fingerprint column exists (the package migration has run). */
    private function ready(): bool
    {
        if (Cache::get('subandl:identity-ready')) {
            return true;
        }

        $ready = Schema::hasColumn(config('subandl.tables.installations', 'license_installations'), 'fingerprint');

        if ($ready) {
            Cache::forever('subandl:identity-ready', true);
        }

        return $ready;
    }
}
