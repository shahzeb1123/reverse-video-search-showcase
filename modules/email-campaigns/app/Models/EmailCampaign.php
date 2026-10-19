<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailCampaign extends Model
{
    public const DRAFT     = 'draft';
    public const SCHEDULED = 'scheduled';
    public const PREPARING = 'preparing';
    public const SENDING   = 'sending';
    public const PAUSED    = 'paused';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const FAILED    = 'failed';

    public const AUDIENCES = [
        'users'       => 'Registered users',
        'subscribers' => 'Newsletter subscribers',
        'both'        => 'Registered users + newsletter subscribers',
    ];

    protected $fillable = [
        'uuid', 'name', 'subject', 'preheader', 'eyebrow', 'headline', 'from_name', 'reply_to', 'body', 'button_label', 'button_url',
        'audience', 'filters', 'status', 'status_note', 'scheduled_at', 'resume_at', 'rate_limit_hits', 'started_at', 'completed_at',
        'throttle_per_minute', 'track_opens', 'track_clicks',
        'total_recipients', 'sent_count', 'failed_count', 'skipped_count',
        'opened_count', 'clicked_count', 'unsubscribed_count', 'created_by',
    ];

    protected $casts = [
        'filters' => 'array', 'track_opens' => 'boolean', 'track_clicks' => 'boolean',
        'scheduled_at' => 'datetime', 'resume_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->uuid = $c->uuid ?: (string) Str::uuid();
        });
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'campaign_id');
    }

    // ── State helpers ─────────────────────────────────────────────────

    public function isEditable(): bool  { return in_array($this->status, [self::DRAFT, self::SCHEDULED], true); }
    public function isRunning(): bool   { return in_array($this->status, [self::PREPARING, self::SENDING], true); }
    public function isLive(): bool      { return in_array($this->status, [self::PREPARING, self::SENDING, self::PAUSED], true); }
    public function isFinished(): bool  { return in_array($this->status, [self::COMPLETED, self::CANCELLED, self::FAILED], true); }
    public function isDeletable(): bool { return ! $this->isRunning(); }

    /** Recipients still waiting to be mailed (live query, not a counter). */
    public function pendingCount(): int
    {
        return $this->recipients()->whereIn('status', [EmailCampaignRecipient::PENDING, EmailCampaignRecipient::SENDING])->count();
    }

    public function progressPercent(): int
    {
        if (! $this->total_recipients) {
            return 0;
        }
        return (int) min(100, round((($this->sent_count + $this->failed_count) / $this->total_recipients) * 100));
    }

    /** "12.3%" of delivered emails, or an em dash before anything was sent. */
    public function rate(int $count): string
    {
        return $this->sent_count > 0 ? number_format($count / $this->sent_count * 100, 1) . '%' : '—';
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::DRAFT     => 'secondary',
            self::SCHEDULED => 'primary',
            self::PREPARING, self::SENDING => 'info',
            self::PAUSED    => 'warning',
            self::COMPLETED => 'success',
            self::CANCELLED => 'dark',
            self::FAILED    => 'danger',
            default         => 'secondary',
        };
    }

    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
