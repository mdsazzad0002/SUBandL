<?php

namespace SUBandL\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class LicenseState extends Model
{
    protected $fillable = [
        'license_key',
        'domain',
        'client_name',
        'client_email',
        'subscription_type',
        'status',
        'expires_at',
        'update_support_expires_at',
        'grace_days',
        'in_grace_period',
        'grace_ends_at',
        'due_amount',
        'payment_info',
        'monthly_fee',
        'backup_enabled',
        'backup_running',
        'backup_started_at',
        'code_integrity_ok',
        'last_verified_at',
        'last_verification_response',
        'last_verification_error',
        'last_update_check_at',
        'last_backup_at',
        'last_backup_status',
        'last_backup_message',
        'update_running',
        'update_started_at',
        'last_update_status',
        'last_update_message',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'update_support_expires_at' => 'datetime',
        'grace_ends_at' => 'date',
        'in_grace_period' => 'boolean',
        'due_amount' => 'decimal:2',
        'monthly_fee' => 'decimal:2',
        'payment_info' => 'array',
        'last_verified_at' => 'datetime',
        'last_update_check_at' => 'datetime',
        'last_backup_at' => 'datetime',
        'backup_enabled' => 'boolean',
        'backup_running' => 'boolean',
        'backup_started_at' => 'datetime',
        'code_integrity_ok' => 'boolean',
        'last_verification_response' => 'array',
        'update_running' => 'boolean',
        'update_started_at' => 'datetime',
    ];

    private const CACHE_KEY = 'subandl:license-state';
    private const CACHE_MINUTES = 5;

    public function getTable()
    {
        return config('subandl.tables.states', 'license_states');
    }

    /**
     * Cached so the many places that call current() in a single request (and across
     * requests, e.g. every page load's license middleware) don't each hit the DB. Any
     * save() invalidates the cache via booted(), so this can never serve a stale row
     * for longer than one write — the TTL is only a safety net.
     */
    public static function current(): self
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_MINUTES), function () {
            // The configured license key is only ever read here — as the ONE-TIME seed
            // value when the row doesn't exist yet (a brand-new install). Once the row
            // exists it never overrides a key saved afterwards.
            return static::query()->firstOrCreate([], [
                'status' => 'unverified',
                'license_key' => config('subandl.license_key'),
            ]);
        });
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->code_integrity_ok;
    }

    public function isMaintenanceExpired(): bool
    {
        if (! $this->update_support_expires_at) {
            return false;
        }

        return $this->update_support_expires_at->lt(now());
    }

    /**
     * True once the app should stop letting the user work and send them to the
     * subscription page instead. Only fires once the license itself is unusable
     * (expired past grace, blocked, invalid) — being in the grace period, or having
     * maintenance lapse on its own, must NOT block browsing.
     */
    public function needsSubscriptionRedirect(): bool
    {
        return ! $this->isUsable();
    }

    /**
     * The active license key — database-driven only. The configured key only seeds
     * the row once (see current()) and is never consulted again after that.
     */
    public static function resolveLicenseKey(): ?string
    {
        return static::current()->license_key;
    }
}
