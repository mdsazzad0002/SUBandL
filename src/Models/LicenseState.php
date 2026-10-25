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
        return $this->status === 'active' && $this->code_integrity_ok && ! $this->isTampered();
    }

    /** A copy of a live install opened locally, or on another device (see IdentityWatch). */
    public function isTampered(): bool
    {
        return \SUBandL\License\IdentityWatch::tamperReason() !== null;
    }

    /** The status to show: tampering overrides the provider's last verdict. */
    public function effectiveStatus(): ?string
    {
        return $this->isTampered() ? 'tampered' : $this->status;
    }

    public function isMaintenanceExpired(): bool
    {
        return ! $this->updatesIncluded();
    }

    /**
     * The software's tutorial videos from the last verification, the intro
     * video first: [['title', 'url', 'youtube_id', 'thumbnail'], …]. The
     * thumbnail is YouTube's own still, null for links that aren't YouTube.
     *
     * @return array<int, array{title: string, url: string, youtube_id: ?string, thumbnail: ?string}>
     */
    public function videos(): array
    {
        $software = $this->last_verification_response['software'] ?? null;
        if (! is_array($software)) {
            return [];
        }

        $list = is_array($software['videos'] ?? null) ? $software['videos'] : [];
        if (! empty($software['intro_video_url']) && is_string($software['intro_video_url'])) {
            array_unshift($list, ['title' => 'Introduction', 'url' => $software['intro_video_url']]);
        }

        $videos = [];
        foreach ($list as $video) {
            $url = is_array($video) ? ($video['url'] ?? null) : null;
            if (! is_string($url) || ! preg_match('#^https?://#i', $url) || isset($videos[$url])) {
                continue;
            }

            $id = self::youtubeId($url);
            $videos[$url] = [
                'title' => trim((string) ($video['title'] ?? '')) ?: 'Video',
                'url' => $url,
                'youtube_id' => $id,
                'thumbnail' => $id ? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" : null,
            ];
        }

        return array_values($videos);
    }

    /** youtu.be/ID, youtube.com/watch?v=ID, /embed/ID, /shorts/ID, /live/ID. */
    public static function youtubeId(string $url): ?string
    {
        $pattern = '#^https?://(?:www\.|m\.)?(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})#i';

        return preg_match($pattern, $url, $match) ? $match[1] : null;
    }

    /**
     * The provider's billing summary from the last verification (plan, fee,
     * due, paid-through, grace, open invoices). Empty for providers that
     * don't send one.
     *
     * @return array<string, mixed>
     */
    public function billing(): array
    {
        $billing = $this->last_verification_response['billing'] ?? null;

        return is_array($billing) ? $billing : [];
    }

    /**
     * May this install receive new versions? Monthly plans: while active.
     * Lifetime plans: only with a paid monthly update subscription.
     */
    public function updatesIncluded(): bool
    {
        $flag = $this->last_verification_response['updates_included'] ?? null;

        if ($flag !== null) {
            return (bool) $flag;
        }

        // Older providers: fall back to the update-support date, if any.
        return ! $this->update_support_expires_at || $this->update_support_expires_at->gte(now());
    }

    /** Payment is late (past the paid-through date or a fee's due date). */
    public function isPaymentLate(): bool
    {
        return (bool) ($this->billing()['payment_late'] ?? $this->in_grace_period);
    }

    /**
     * Everything unpaid, as the provider reports it: due_amount sums ALL open
     * invoices — the subscription fees (fee_due_amount) included. A late
     * payment with nothing invoiced yet still owes this period's fee, so the
     * monthly fee stands in for it rather than showing 0.
     */
    public function totalDue(): float
    {
        $total = (float) ($this->billing()['due_amount'] ?? $this->due_amount ?? 0);

        return $total > 0 ? $total : $this->unbilledFee();
    }

    /** The subscription-fee part of totalDue() (current and older unpaid fees). */
    public function feeDue(): float
    {
        $fee = (float) ($this->billing()['fee_due_amount'] ?? 0);

        return $fee > 0 ? $fee : $this->unbilledFee();
    }

    /** Other unpaid invoices (installation, extras): totalDue() minus the fees. */
    public function otherDue(): float
    {
        return max(0.0, round($this->totalDue() - $this->feeDue(), 2));
    }

    private function unbilledFee(): float
    {
        if ((float) ($this->billing()['due_amount'] ?? $this->due_amount ?? 0) > 0 || ! $this->isPaymentLate()) {
            return 0.0;
        }

        return (float) ($this->billing()['monthly_fee'] ?? $this->monthly_fee ?? 0);
    }

    /** Money is owed, grace is running, or the license is unusable. */
    public function needsAttention(): bool
    {
        return $this->totalDue() > 0 || $this->in_grace_period || $this->isPaymentLate() || ! $this->isUsable();
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
     * Where an unusable license sends the user: the Update & Backup page
     * (full-screen panel), which explains the license problem and keeps
     * backup and update status in reach, with the License tab one click
     * away. Users allowed neither there nor on the License tab get the plain
     * explanatory page.
     */
    public function redirectRouteName(): string
    {
        if (\SUBandL\Support\Access::allows('update') || \SUBandL\Support\Access::allows('backup')) {
            return 'subscription.update';
        }

        return \SUBandL\Support\Access::allows('license')
            ? 'subscription.license'
            : 'license.verification-required';
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
