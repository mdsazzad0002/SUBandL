<?php

namespace SUBandL\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Every request to the provider goes through here, so HTTPS works the same on
 * every host. PHP on Windows (XAMPP, WAMP, Laragon) ships without a CA
 * bundle, so a plain request fails with "cURL error 60: SSL certificate
 * problem: unable to get local issuer certificate". composer/ca-bundle finds
 * the system's bundle, or falls back to the one it ships with.
 *
 * config('subandl.http.verify') overrides it: a path to a CA file, or false
 * to switch verification off (not recommended).
 */
class HttpClient
{
    private static string|bool|null $verify = null;

    public static function request(): PendingRequest
    {
        return Http::withOptions(['verify' => self::verify()]);
    }

    /** What the HTTPS check uses: true (php.ini / system default), a CA file path, or false. */
    public static function verify(): string|bool
    {
        if (self::$verify !== null) {
            return self::$verify;
        }

        $configured = config('subandl.http.verify');
        if (is_bool($configured) || (is_string($configured) && $configured !== '')) {
            return self::$verify = $configured;
        }

        // php.ini already points at a bundle (most Linux packages, a fixed XAMPP).
        foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
            $file = (string) ini_get($setting);
            if ($file !== '' && Platform::isFile($file)) {
                return self::$verify = true;
            }
        }

        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            try {
                return self::$verify = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            } catch (\Throwable $e) {
                // fall through to the default
            }
        }

        return self::$verify = true;
    }
}
