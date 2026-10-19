<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient as Recipient;
use App\Models\EmailSuppression;
use App\Models\NewsletterSubscriber;
use App\Services\EmailCampaignService;
use App\Services\MailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends ONE campaign email. One job per recipient means Laravel's own retry,
 * backoff and failure handling apply per address, and one bad mailbox can
 * never stall the rest of the campaign.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    /** Seconds before attempt 2 and 3. */
    private const BACKOFF = [60, 300];

    /** Fingerprint of the SMTP settings the cached mailer was built with. */
    private static ?string $mailerFingerprint = null;

    public function __construct(public readonly int $recipientId)
    {
        $this->onQueue(EmailCampaignService::QUEUE);
    }

    public function handle(EmailCampaignService $service): void
    {
        $recipient = Recipient::with('campaign')->find($this->recipientId);
        $campaign  = $recipient?->campaign;

        // Paused / cancelled / gone: leave the recipient untouched (pending) and drop the job.
        // Resuming queues a fresh job for everything still pending.
        if (! $recipient || ! $campaign || $campaign->status !== EmailCampaign::SENDING) {
            return;
        }

        // Atomic claim: if two jobs for the same recipient overlap, only one wins.
        $claimed = Recipient::whereKey($recipient->id)->where('status', Recipient::PENDING)
            ->update(['status' => Recipient::SENDING, 'claimed_at' => now(), 'attempts' => $recipient->attempts + 1, 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }

        // Opt-out may have arrived after the list was built.
        if (EmailSuppression::isSuppressed($recipient->email)) {
            $recipient->update(['status' => Recipient::SKIPPED, 'error' => 'Unsubscribed / suppressed']);
            EmailCampaign::whereKey($campaign->id)->increment('skipped_count');
            $service->maybeComplete($campaign);
            return;
        }

        try {
            $this->refreshMailer();
            Mail::to($recipient->email)->send(new CampaignMail($campaign, $recipient));
        } catch (Throwable $e) {
            $this->handleFailure($service, $campaign, $recipient, $e);
            return;
        }

        Recipient::whereKey($recipient->id)->update(['status' => Recipient::SENT, 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
        EmailCampaign::whereKey($campaign->id)->increment('sent_count');
        $service->maybeComplete($campaign);
    }

    private function handleFailure(EmailCampaignService $service, EmailCampaign $campaign, Recipient $recipient, Throwable $e): void
    {
        $message   = mb_substr($e->getMessage(), 0, 1000);

        // The provider's own hourly cap: this email was fine. Put it back, pause the campaign, and
        // let the scheduler resume it. Not counted as an attempt or a failure.
        if ($service->isRateLimited($e)) {
            Recipient::whereKey($recipient->id)->update([
                'status' => Recipient::PENDING, 'error' => $message,
                'attempts' => max(0, $recipient->attempts - 1), 'updated_at' => now(),
            ]);
            $service->pauseForRateLimit($campaign);
            return;
        }

        $permanent = $service->isPermanentFailure($e);

        // Transient problem and attempts left: back to pending and try again later.
        if (! $permanent && $this->attempts() < $this->tries) {
            Recipient::whereKey($recipient->id)->update(['status' => Recipient::PENDING, 'error' => $message, 'updated_at' => now()]);
            $this->release(self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);
            return;
        }

        $this->markFailed($recipient, $campaign, ($permanent ? '[bounce] ' : '') . $message);

        if ($permanent) {
            // A mailbox that does not exist must never be mailed again, by any campaign.
            EmailSuppression::add($recipient->email, 'bounced', $campaign->id, mb_substr($message, 0, 250));
        } else {
            Log::warning('Email campaign send failed', ['campaign' => $campaign->id, 'email' => $recipient->email, 'error' => $message]);
            $service->autoPauseOnFailureStreak($campaign);
        }

        $service->maybeComplete($campaign);
    }

    private function markFailed(Recipient $recipient, EmailCampaign $campaign, string $error): void
    {
        $changed = Recipient::whereKey($recipient->id)->where('status', '!=', Recipient::SENT)
            ->update(['status' => Recipient::FAILED, 'error' => $error, 'updated_at' => now()]);

        if ($changed) {
            EmailCampaign::whereKey($campaign->id)->increment('failed_count');
        }
    }

    /** Applies the admin-managed SMTP settings and rebuilds the cached mailer only when they changed. */
    private function refreshMailer(): void
    {
        MailConfigService::configure();

        $fingerprint = md5(json_encode([config('mail.default'), config('mail.mailers.smtp'), config('mail.from')]));
        if (self::$mailerFingerprint !== null && self::$mailerFingerprint !== $fingerprint) {
            Mail::purge();
        }
        self::$mailerFingerprint = $fingerprint;
    }

    /** Timeout / worker killed: do not leave the recipient stuck, and do not count it twice. */
    public function failed(Throwable $e): void
    {
        $recipient = Recipient::with('campaign')->find($this->recipientId);
        if (! $recipient || ! $recipient->campaign || in_array($recipient->status, [Recipient::SENT, Recipient::FAILED, Recipient::SKIPPED], true)) {
            return;
        }

        $this->markFailed($recipient, $recipient->campaign, mb_substr($e->getMessage(), 0, 1000));
        app(EmailCampaignService::class)->maybeComplete($recipient->campaign);
    }
}
