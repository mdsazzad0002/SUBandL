<?php

namespace SUBandL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * What the package did with the provider and why — health checks, live
 * license/update checks, retries, skips — so the Update & Backup page can
 * explain what happened (e.g. "update check skipped: provider unreachable
 * after 2 attempts, next try 21:48"). Backups and applied updates keep their
 * own histories; this log is the story around them.
 */
class ActivityLog extends Model
{
    public const KEEP = 500;

    protected $fillable = ['type', 'ok', 'message', 'context'];

    protected $casts = [
        'ok' => 'boolean',
        'context' => 'array',
    ];

    public function getTable()
    {
        return config('subandl.tables.activity_logs', 'license_activity_logs');
    }

    /**
     * @param  string  $type  health | license | update-check | update | backup
     */
    public static function record(string $type, bool $ok, string $message, array $context = []): void
    {
        Log::log($ok ? 'info' : 'warning', "SUBandL [{$type}] {$message}", $context);

        try {
            static::create(['type' => $type, 'ok' => $ok, 'message' => $message, 'context' => $context ?: null]);

            // Keep the table small.
            if (random_int(1, 50) === 1) {
                $cutoff = static::query()->latest('id')->skip(self::KEEP)->value('id');
                if ($cutoff) {
                    static::query()->where('id', '<=', $cutoff)->delete();
                }
            }
        } catch (\Throwable $e) {
            // Table not migrated yet: the Laravel log above still has it.
        }
    }
}
