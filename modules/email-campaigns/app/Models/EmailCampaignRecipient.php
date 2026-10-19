<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailCampaignRecipient extends Model
{
    public const PENDING = 'pending';
    public const SENDING = 'sending';
    public const SENT    = 'sent';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'campaign_id', 'user_id', 'email', 'name', 'token', 'status', 'attempts', 'error',
        'claimed_at', 'sent_at', 'opened_at', 'open_count', 'clicked_at', 'click_count', 'unsubscribed_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime', 'sent_at' => 'datetime', 'opened_at' => 'datetime',
        'clicked_at' => 'datetime', 'unsubscribed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            $r->token = $r->token ?: Str::random(32);
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function firstName(): string
    {
        $first = trim(explode(' ', trim((string) $this->name))[0] ?? '');
        return $first !== '' ? $first : 'there';
    }

    public function displayName(): string
    {
        $name = trim((string) $this->name);
        return $name !== '' ? $name : 'there';
    }
}
