<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global do-not-email list. Checked when a campaign builds its audience AND
 * again right before each send, so an unsubscribe that lands mid-campaign
 * stops the remaining emails.
 */
class EmailSuppression extends Model
{
    protected $fillable = ['email', 'reason', 'campaign_id', 'note'];

    public static function add(string $email, string $reason = 'unsubscribed', ?int $campaignId = null, ?string $note = null): self
    {
        return static::updateOrCreate(
            ['email' => strtolower(trim($email))],
            ['reason' => $reason, 'campaign_id' => $campaignId, 'note' => $note],
        );
    }

    public static function isSuppressed(string $email): bool
    {
        return static::where('email', strtolower(trim($email)))->exists();
    }
}
