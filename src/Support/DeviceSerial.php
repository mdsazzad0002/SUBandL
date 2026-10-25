<?php

namespace SUBandL\Support;

/**
 * The machine's own serial, which a copied project or database can't take
 * along to another computer:
 *
 *  - Windows: the hardware (SMBIOS) UUID — wmic, else PowerShell. Boards that
 *    ship a placeholder UUID fall back to MachineGuid, which Windows creates
 *    at install time.
 *  - macOS: IOPlatformUUID, the hardware UUID.
 *  - Linux: /etc/machine-id (the DMI UUID is readable by root only, so the
 *    web server and cron would disagree).
 *
 * Reading it starts a short process, so the value is kept in the system temp
 * folder — it belongs to the machine and is never copied with the project.
 * null when nothing can be read (exec disabled, open_basedir).
 */
class DeviceSerial
{
    /** Placeholder UUIDs some boards ship with — shared by many machines. */
    private const PLACEHOLDERS = [
        '03000200-0400-0500-0006-000700080009',
        '00020003-0004-0005-0006-000700080009',
        '12345678-1234-5678-90ab-cddeefaabbcc',
    ];

    private static ?string $serial = null;

    private static bool $resolved = false;

    public static function get(): ?string
    {
        if (self::$resolved) {
            return self::$serial;
        }
        self::$resolved = true;

        $cacheFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'subandl-device-serial';
        if (Platform::isFile($cacheFile) && self::valid($cached = trim((string) @file_get_contents($cacheFile)))) {
            return self::$serial = $cached;
        }

        $serial = self::read();
        if ($serial !== null) {
            @file_put_contents($cacheFile, $serial);
        }

        return self::$serial = $serial;
    }

    private static function read(): ?string
    {
        if (Platform::isWindows()) {
            return self::uuid(self::run('wmic csproduct get uuid'))
                ?? self::uuid(self::run('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID"'))
                ?? self::machineGuid();
        }

        if (Platform::isMac()) {
            $output = self::run('/usr/sbin/ioreg -rd1 -c IOPlatformExpertDevice');

            return preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', (string) $output, $m) ? self::uuid($m[1]) : null;
        }

        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $file) {
            $id = strtolower(trim((string) (Platform::isFile($file) ? @file_get_contents($file) : '')));
            if (self::valid($id)) {
                return $id;
            }
        }

        return null;
    }

    private static function machineGuid(): ?string
    {
        // /reg:64 — a 32-bit PHP would otherwise read Wow6432Node, which has no MachineGuid.
        $reg = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\reg.exe';
        $output = self::run(escapeshellarg($reg) . ' query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid /reg:64');

        return preg_match('/MachineGuid\s+REG_SZ\s+(\S+)/i', (string) $output, $m) ? self::uuid($m[1]) : null;
    }

    /** The first real UUID in a command's output. */
    private static function uuid(?string $output): ?string
    {
        if (! preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', (string) $output, $m)) {
            return null;
        }
        $uuid = strtolower($m[0]);

        return self::valid($uuid) && ! in_array($uuid, self::PLACEHOLDERS, true) ? $uuid : null;
    }

    /** Hex (dashes allowed), long enough, and not blank like all zeros or all F. */
    private static function valid(string $id): bool
    {
        $hex = str_replace('-', '', $id);

        return (bool) preg_match('/^[0-9a-f]{16,64}$/i', $hex) && count(array_unique(str_split(strtolower($hex)))) > 2;
    }

    private static function run(string $command): ?string
    {
        if (Platform::functionEnabled('exec')) {
            $output = [];
            $exit = 1;
            @exec($command . ' 2>' . Platform::nullDevice(), $output, $exit);

            return $exit === 0 ? implode("\n", $output) : null;
        }

        if (Platform::functionEnabled('shell_exec')) {
            $output = @shell_exec($command . ' 2>' . Platform::nullDevice());

            return is_string($output) ? $output : null;
        }

        return null;
    }
}
