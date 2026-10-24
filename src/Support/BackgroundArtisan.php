<?php

namespace SUBandL\Support;

/**
 * Starts an artisan command as a fully detached OS process, so it survives the
 * end of the web request that triggered it.
 *
 * Symfony's Process::start() is non-blocking, but the Process object's
 * destructor stops the child once it goes out of scope (at the latest when the
 * request finishes) — that silently killed slower commands before they could
 * save their result. Instead:
 *
 *  - Linux: nohup + setsid (a new session, not tied to this PHP process);
 *  - macOS: nohup (macOS has no setsid);
 *  - Windows (XAMPP, WAMP, Laragon, IIS): `start "" /B` through popen, which
 *    returns as soon as the child has been launched.
 */
class BackgroundArtisan
{
    /**
     * @return bool false when the host cannot spawn processes (exec/popen
     *              disabled), so the caller can fall back to running inline.
     */
    public static function dispatch(string $command, array $options = []): bool
    {
        try {
            $parts = [
                escapeshellarg(CliPhpBinary::resolve()),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($command),
            ];
            foreach ($options as $option) {
                $parts[] = escapeshellarg($option);
            }
            $commandLine = implode(' ', $parts);

            if (Platform::isWindows()) {
                return self::popen('start "" /B ' . $commandLine . ' >NUL 2>&1');
            }

            $detach = self::hasSetsid() ? 'nohup setsid ' : 'nohup ';
            $line = $detach . $commandLine . ' > /dev/null 2>&1 &';

            if (Platform::functionEnabled('exec')) {
                @exec($line);

                return true;
            }

            return self::popen($line);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function popen(string $line): bool
    {
        if (! Platform::functionEnabled('popen') || ! Platform::functionEnabled('pclose')) {
            return false;
        }

        $handle = @popen($line, 'r');
        if ($handle === false) {
            return false;
        }
        pclose($handle);

        return true;
    }

    /** How a background task is started here — shown by subandl:status. */
    public static function method(): string
    {
        if (Platform::isWindows()) {
            return Platform::functionEnabled('popen') ? 'start /B (popen)' : 'none (popen disabled)';
        }
        if (! Platform::functionEnabled('exec') && ! Platform::functionEnabled('popen')) {
            return 'none (exec and popen disabled)';
        }

        return (self::hasSetsid() ? 'nohup setsid' : 'nohup') . (Platform::functionEnabled('exec') ? ' (exec)' : ' (popen)');
    }

    private static function hasSetsid(): bool
    {
        foreach (['/usr/bin/setsid', '/bin/setsid', '/usr/local/bin/setsid', '/usr/sbin/setsid'] as $path) {
            if (Platform::isExecutable($path)) {
                return true;
            }
        }

        return false;
    }
}
