<?php

namespace SUBandL\License;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use SUBandL\Support\DeviceSerial;

/**
 * Knows where this installation runs and whether that is where it is licensed:
 *
 *  - environment — "live" on a public domain; "local" on localhost, any IP
 *    (private or public) and dev names (a bare host name, *.test, *.local …);
 *  - domain — the public domain the install was verified on;
 *  - device — the machine's own serial (see DeviceSerial).
 *
 * An install is TAMPERED when it is licensed for a public domain but opened
 * locally, or when it runs on another device than the one it is bound to.
 * That is decided fresh on every request and never stored in the license
 * state: the provider only sees the copied device_id, so its verdict can't
 * tell a copy from the original and must not clear it.
 *
 * The known device and domain live in the database (license_installations:
 * fingerprint + domain). Nothing known yet is NOT a change: the current
 * device/domain become the baseline, without contacting the provider.
 */
class IdentityWatch
{
    /** Marks a fingerprint taken from the device serial; older ones hash host name + folder. */
    private const SERIAL_PREFIX = 'sn:';

    /** Top-level names that never reach the public internet. */
    private const LOCAL_TLDS = ['localhost', 'test', 'local', 'lan', 'internal', 'home', 'corp', 'intranet', 'private', 'invalid', 'example', 'arpa'];

    private static ?string $tamper = null;

    private static bool $tamperChecked = false;

    /** A public domain (lowercase, keeps a non-standard port), or null when the host is local. */
    public static function domain(?string $host): ?string
    {
        $host = strtolower(trim((string) $host, ' .'));
        $bare = preg_replace('/:\d+$/', '', $host);

        if ($host === ''
            || filter_var(trim($bare, '[]'), FILTER_VALIDATE_IP) !== false
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || ! str_contains($bare, '.')
            || in_array(substr((string) strrchr($bare, '.'), 1), self::LOCAL_TLDS, true)) {
            return null;
        }

        return $host;
    }

    public static function isLocal(?string $host): bool
    {
        return self::domain($host) === null;
    }

    /**
     * "live" or "local" for the host the site is browsed on. Console runs have
     * none, so they go by the known domain.
     */
    public static function environment(): string
    {
        $request = self::browserRequest();

        return self::isLocal($request ? $request->getHost() : self::knownDomain()) ? 'local' : 'live';
    }

    /**
     * This machine: its serial when one can be read, else host name + install
     * folder (symlinks resolved, forward slashes, case-folded on Windows).
     */
    public static function device(): string
    {
        $serial = DeviceSerial::get();

        return $serial !== null ? self::SERIAL_PREFIX . sha1($serial) : self::hostFolderDevice();
    }

    private static function hostFolderDevice(): string
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

    /**
     * The known public domain — sent as the binding domain by background
     * (console) checks, which have no request of their own.
     */
    public static function knownDomain(): ?string
    {
        return self::domain(InstallationIdentity::current()->domain);
    }

    /** Where the install is bound, as stored — a public domain, or a local host such as 127.0.0.1. */
    public static function boundHost(): ?string
    {
        return InstallationIdentity::current()->domain ?: null;
    }

    /**
     * Why this install looks like a copy, or null. Worked out once per
     * request (or console run).
     */
    public static function tamperReason(): ?string
    {
        if (self::$tamperChecked) {
            return self::$tamper;
        }
        self::$tamperChecked = true;

        try {
            if (! self::ready()) {
                return self::$tamper = null;
            }
            $installation = InstallationIdentity::current();
        } catch (\Throwable $e) {
            // No tables yet (fresh install, before the migration).
            return self::$tamper = null;
        }

        $known = self::domain($installation->domain);
        $request = self::browserRequest();
        if ($known !== null && $request && self::isLocal($request->getHost())) {
            return self::$tamper = "This installation is licensed for {$known}, but it is running on {$request->getHost()} (local).";
        }

        // Only a serial fingerprint is enforced, and only while this machine's
        // serial can be read: a host that later disables exec must not lock out.
        $stored = (string) $installation->fingerprint;
        if (str_starts_with($stored, self::SERIAL_PREFIX) && DeviceSerial::get() !== null && $stored !== self::device()) {
            return self::$tamper = 'This installation is licensed to another device.';
        }

        return self::$tamper = null;
    }

    /**
     * Whether this request comes from a public domain other than the known
     * one, so the provider should confirm the move. Records a baseline device
     * and domain when none is known. A different device is not a "change" to
     * re-verify — the provider can't tell a copy apart — see tamperReason().
     */
    public function changed(Request $request): bool
    {
        if (! self::ready()) {
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
        $device = self::device();
        $stored = (string) $installation->fingerprint;
        // No device yet, or one from before device serials: this device becomes
        // the baseline. An old host + folder hash is not enforced — a genuine
        // site whose host name changed would be locked out, and the old code
        // re-accepted those anyway.
        if ($stored === '' || (! str_starts_with($stored, self::SERIAL_PREFIX) && $stored !== $device)) {
            $baseline['fingerprint'] = $device;
        }
        if ($knownDomain === null && $domain !== null) {
            $baseline['domain'] = $domain;
        }
        if ($baseline) {
            $installation->forceFill($baseline)->save();
        }

        return $domain !== null && $knownDomain !== null && $domain !== $knownDomain;
    }

    /**
     * The provider answered a verification on this public domain: it is the
     * known one from now on. The device is only filled in when none is known —
     * never replaced, since the provider's answer can't vouch for a machine.
     */
    public function remember(?Request $request = null): void
    {
        if (! self::ready()) {
            return;
        }

        $installation = InstallationIdentity::current();
        $domain = $request ? self::domain($request->getHttpHost()) : null;
        $canonical = self::domain(parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($canonical !== null) {
            $domain = $canonical;
        }

        $installation->forceFill(array_filter([
            'fingerprint' => $installation->fingerprint ? null : self::device(),
            'domain' => $domain,
        ]))->save();
    }

    /**
     * A new license key was just accepted: this install now belongs where it
     * runs — this device, and the host it is browsed on. A local host
     * (localhost, 127.0.0.1) replaces a live domain from before; domain()
     * still reads it as local, so it is never enforced or sent as a domain.
     */
    public function rebind(Request $request): void
    {
        if (! self::ready()) {
            return;
        }

        $host = strtolower($request->getHost());

        InstallationIdentity::current()->forceFill([
            'fingerprint' => self::device(),
            'domain' => self::domain($host) ?? ($host !== '' ? $host : null),
        ])->save();

        self::$tamperChecked = false;
    }

    private static function browserRequest(): ?Request
    {
        return app()->runningInConsole() ? null : request();
    }

    /** The fingerprint column exists (the package migration has run). */
    private static function ready(): bool
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
