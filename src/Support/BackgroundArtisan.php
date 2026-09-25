<?php

namespace SUBandL\Support;

/**
 * Starts an artisan command as a fully detached OS process, so it survives the
 * end of the web request that triggered it.
 *
 * Symfony's Process::start() is non-blocking, but the Process object's
 * destructor stops the child once it goes out of scope (at the latest when the
 * request finishes) — that silently killed slower commands before they could
 * save their result. A new session + nohup is not tied to this PHP process.
 */
class BackgroundArtisan
{
    /**
     * @return bool false when the host cannot spawn processes (exec disabled),
     *              so the caller can fall back to running inline.
     */
    public static function dispatch(string $command, array $options = []): bool
    {
        if (! function_exists('exec') || DIRECTORY_SEPARATOR === '\\') {
            return false;
        }

        try {
            $parts = [
                escapeshellarg(CliPhpBinary::resolve()),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($command),
            ];
            foreach ($options as $option) {
                $parts[] = escapeshellarg($option);
            }

            exec('nohup setsid ' . implode(' ', $parts) . ' > /dev/null 2>&1 &');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
