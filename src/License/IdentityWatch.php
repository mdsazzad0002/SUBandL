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

    /**
     * This machine + install folder, hashed. The folder is normalised so the
     * web server and the CLI agree on it: symlinks resolved, forward slashes,
     * and on Windows case-folded (Apache may say C:\xampp, a console c:\xampp).
     */
    public static function device(): string
    {
        $path = @realpath(base_path()) ?: base_path();
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $host = php_uname('n');

        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $host = strtolower($host);
        }

        return sha1($host . '|' . $path);
    }

    /** The fingerprint as computed before it was normalised — still accepted. */
    private static function legacyDevice(): string
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
        // Behind reverse proxies getHttpHost() may reflect an internal host
        // unless trusted proxy headers are configured. Prefer the app URL's
        // canonical host to avoid false domain-change verdicts.
        $domain = self::domain($request->getHttpHost());
        $canonical = self::domain(parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($canonical !== null) {
            $domain = $canonical;
        }
        $knownDomain = self::domain($installation->domain);

        $baseline = [];
        if (! $installation->fingerprint) {
            $baseline['fingerprint'] = self::device();
        }
        if ($knownDomain === null && $domain !== null) {
            $baseline['domain'] = $domain;
        }
        // Recorded with the old formula on this same machine and folder:
        // upgrade it quietly rather than report a device change.
        if ($installation->fingerprint && $installation->fingerprint !== self::device() && $installation->fingerprint === self::legacyDevice()) {
            $baseline['fingerprint'] = self::device();
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
        $canonical = self::domain(parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($canonical !== null) {
            $domain = $canonical;
        }

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
