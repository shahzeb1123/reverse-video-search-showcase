<?php

namespace App\Services;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Facades\URL;

/**
 * Turns a campaign's stored fields into the exact email one recipient gets:
 * merge tags, click-tracking links, unsubscribe link. Shared by the admin
 * preview, the test send and the real send.
 */
class EmailCampaignRenderer
{
    /** Tags admins can type into the subject, body, headline or button. */
    public const MERGE_TAGS = [
        '{{first_name}}'      => 'First name (or "there")',
        '{{name}}'            => 'Full name (or "there")',
        '{{email}}'           => 'Recipient email address',
        '{{app_name}}'        => 'Site name',
        '{{app_url}}'         => 'Site address',
        '{{unsubscribe_url}}' => 'One-click unsubscribe link',
    ];

    private const TAG_PATTERN = '/\{\{\s*(first_name|name|email|app_name|app_url|unsubscribe_url)\s*\}\}/i';

    // ── URLs ──────────────────────────────────────────────────────────

    public function unsubscribeUrl(EmailCampaignRecipient $r): string
    {
        // No expiry: an unsubscribe link in an old email must keep working.
        return URL::signedRoute('email.unsubscribe', ['token' => $r->token]);
    }

    public function openPixelUrl(EmailCampaignRecipient $r): string
    {
        return route('email.track.open', ['token' => $r->token]);
    }

    // ── Rendering ─────────────────────────────────────────────────────

    public function subject(EmailCampaign $c, EmailCampaignRecipient $r, bool $test = false): string
    {
        return $this->merge($c->subject, $r, false, $test);
    }

    public function plain(?string $text, EmailCampaignRecipient $r, bool $test = false): string
    {
        return $this->merge((string) $text, $r, false, $test);
    }

    /** Fully personalised body HTML (merge tags + tracked links). */
    public function body(EmailCampaign $c, EmailCampaignRecipient $r, bool $test = false): string
    {
        $html = $this->merge($c->body, $r, true, $test);

        if ($c->track_clicks && ! $test) {
            $html = $this->trackLinks($html, $r);
        }

        return $html;
    }

    /** The call-to-action button's destination (tracked unless this is a test), or null when there is no button. */
    public function buttonUrl(EmailCampaign $c, EmailCampaignRecipient $r, bool $test = false): ?string
    {
        if (! $c->button_label || ! $c->button_url) {
            return null;
        }

        $url = $this->merge($c->button_url, $r, false, $test);
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return ($c->track_clicks && ! $test) ? $this->trackedUrl($url, $r) : $url;
    }

    public function merge(string $text, EmailCampaignRecipient $r, bool $html, bool $test = false): string
    {
        $esc   = fn (string $v) => $html ? e($v) : $v;

        $map = [
            'first_name'      => $esc($r->firstName()),
            'name'            => $esc($r->displayName()),
            'email'           => $esc($r->email),
            'app_name'        => $esc((string) config('app.name')),
            'app_url'         => rtrim((string) config('app.url'), '/'),
            'unsubscribe_url' => $test ? '#' : $this->unsubscribeUrl($r),
        ];

        return preg_replace_callback(self::TAG_PATTERN, fn ($m) => $map[strtolower($m[1])], $text);
    }

    /**
     * Rewrites every http(s) link to a signed redirect that records the click.
     * The signature stops the redirect being used as an open redirect.
     */
    private function trackLinks(string $html, EmailCampaignRecipient $r): string
    {
        return preg_replace_callback(
            '/(<a\b[^>]*?\shref=)(["\'])(.*?)\2/is',
            function ($m) use ($r) {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);

                if (! preg_match('#^https?://#i', $url) || str_contains($url, '/email/unsubscribe/') || str_contains($url, '/e/c/')) {
                    return $m[0];
                }

                return $m[1] . $m[2] . e($this->trackedUrl($url, $r)) . $m[2];
            },
            $html,
        );
    }

    private function trackedUrl(string $url, EmailCampaignRecipient $r): string
    {
        return URL::signedRoute('email.track.click', [
            'token' => $r->token,
            'u'     => rtrim(strtr(base64_encode($url), '+/', '-_'), '='),
        ]);
    }

    public static function decodeUrl(string $encoded): ?string
    {
        $url = base64_decode(strtr($encoded, '-_', '+/'), true);

        return ($url !== false && preg_match('#^https?://#i', $url)) ? $url : null;
    }
}
