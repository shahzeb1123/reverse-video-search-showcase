<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\EmailCampaignRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One marketing email to one recipient. Deliberately NOT ShouldQueue: the
 * SendCampaignEmail job is already the queued unit, and it needs to see the
 * SMTP result itself so it can record sent / failed per recipient.
 */
class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EmailCampaign $campaign,
        public readonly EmailCampaignRecipient $recipient,
        public readonly bool $test = false,
    ) {}

    private function renderer(): EmailCampaignRenderer
    {
        return app(EmailCampaignRenderer::class);
    }

    public function envelope(): Envelope
    {
        $subject = $this->renderer()->subject($this->campaign, $this->recipient, $this->test);
        if ($this->test) {
            $subject = '[TEST] ' . $subject;
        }

        $from = null;
        if ($this->campaign->from_name) {
            $from = new Address((string) config('mail.from.address'), $this->campaign->from_name);
        }

        $replyTo = [];
        if ($this->campaign->reply_to && filter_var($this->campaign->reply_to, FILTER_VALIDATE_EMAIL)) {
            $replyTo[] = new Address($this->campaign->reply_to);
        }

        return new Envelope(from: $from, replyTo: $replyTo, subject: $subject);
    }

    public function headers(): Headers
    {
        if ($this->test) {
            return new Headers();
        }

        $url = $this->renderer()->unsubscribeUrl($this->recipient);

        // RFC 8058 one-click unsubscribe: Gmail/Yahoo show their own button for it.
        return new Headers(text: [
            'List-Unsubscribe'      => '<' . $url . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'X-Campaign-Id'         => $this->campaign->uuid,
        ]);
    }

    public function content(): Content
    {
        $r = $this->renderer();

        return new Content(
            view: 'emails.campaign',
            with: [
                'subject'        => $r->subject($this->campaign, $this->recipient, $this->test),
                'preheader'      => $r->plain($this->campaign->preheader, $this->recipient, $this->test),
                'eyebrow'        => $r->plain($this->campaign->eyebrow, $this->recipient, $this->test),
                'headline'       => $r->plain($this->campaign->headline, $this->recipient, $this->test),
                'buttonLabel'    => $r->plain($this->campaign->button_label, $this->recipient, $this->test),
                'buttonUrl'      => $r->buttonUrl($this->campaign, $this->recipient, $this->test),
                'body'           => $r->body($this->campaign, $this->recipient, $this->test),
                'unsubscribeUrl' => $this->test ? '#' : $r->unsubscribeUrl($this->recipient),
                'pixelUrl'       => ($this->campaign->track_opens && ! $this->test) ? $r->openPixelUrl($this->recipient) : null,
                'reason'         => $this->recipient->user_id
                    ? 'You are receiving this email because you have an account with ' . config('app.name') . '.'
                    : 'You are receiving this email because you subscribed to ' . config('app.name') . ' updates.',
            ],
        );
    }
}
