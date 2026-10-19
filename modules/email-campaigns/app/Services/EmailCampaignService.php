<?php

namespace App\Services;

use App\Jobs\PrepareEmailCampaign;
use App\Jobs\SendCampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient as Recipient;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Audience building and the campaign lifecycle (start / pause / resume /
 * cancel / retry / complete). Controllers and jobs call this; neither
 * contains lifecycle rules of its own.
 *
 * Queue: everything runs on the dedicated "campaigns" queue so a big blast can
 * never delay video searches on "default" (needs its own worker; see deploy notes).
 */
class EmailCampaignService
{
    public const QUEUE = 'campaigns';

    // ── Audience ──────────────────────────────────────────────────────

    /** Registered users matching the segment filters (suppressed addresses excluded unless asked). */
    public function usersQuery(array $filters = [], bool $excludeSuppressed = true): Builder
    {
        $q = User::query()
            ->where('is_active', true)
            ->whereNotNull('email')->where('email', '!=', '');

        if (! empty($filters['verified_only'])) {
            $q->whereNotNull('email_verified_at');
        }
        if (! empty($filters['registered_from'])) {
            $q->where('created_at', '>=', $filters['registered_from']);
        }
        if (! empty($filters['registered_to'])) {
            $q->where('created_at', '<=', $filters['registered_to']);
        }

        // "Skip people already emailed by campaign X" (any status, so it also works while X is still sending).
        if (! empty($filters['exclude_campaign_id'])) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('email_campaign_recipients')
                ->where('email_campaign_recipients.campaign_id', (int) $filters['exclude_campaign_id'])
                ->whereColumn('email_campaign_recipients.email', 'users.email'));
        }

        $premium = fn ($sub) => $sub->select(DB::raw(1))->from('subscriptions')
            ->whereColumn('subscriptions.user_id', 'users.id')
            ->where('subscriptions.status', 'active')
            ->where(fn ($w) => $w->whereNull('subscriptions.ends_at')->orWhere('subscriptions.ends_at', '>', now()));

        match ($filters['plan'] ?? 'all') {
            'premium' => $q->whereExists($premium),
            'free'    => $q->whereNotExists($premium),
            default   => null,
        };

        if ($excludeSuppressed) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('email_suppressions')
                ->whereColumn('email_suppressions.email', 'users.email'));
        }

        return $q;
    }

    /**
     * Active newsletter subscribers. When the audience is "both", subscribers who
     * are also registered users are left to the users pass so nobody is mailed twice.
     */
    public function subscribersQuery(bool $excludeSuppressed = true, bool $skipRegisteredUsers = false): Builder
    {
        $q = NewsletterSubscriber::query()->where('status', 'active');

        if ($excludeSuppressed) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('email_suppressions')
                ->whereColumn('email_suppressions.email', 'newsletter_subscribers.email'));
        }
        if ($skipRegisteredUsers) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('users')
                ->whereColumn('users.email', 'newsletter_subscribers.email'));
        }

        return $q;
    }

    /** @return array{users:int, subscribers:int, total:int, suppressed:int} */
    public function audienceCounts(string $audience, array $filters = []): array
    {
        $users = in_array($audience, ['users', 'both'], true) ? $this->usersQuery($filters)->count() : 0;
        $subs  = in_array($audience, ['subscribers', 'both'], true)
            ? $this->subscribersQuery(true, $audience === 'both')->count() : 0;

        return [
            'users'       => $users,
            'subscribers' => $subs,
            'total'       => $users + $subs,
            'suppressed'  => $this->suppressedCount($audience, $filters),
        ];
    }

    private function suppressedCount(string $audience, array $filters): int
    {
        $n = 0;
        if (in_array($audience, ['users', 'both'], true)) {
            $n += $this->usersQuery($filters, false)->count() - $this->usersQuery($filters)->count();
        }
        if (in_array($audience, ['subscribers', 'both'], true)) {
            $skip = $audience === 'both';
            $n += $this->subscribersQuery(false, $skip)->count() - $this->subscribersQuery(true, $skip)->count();
        }

        return $n;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────

    /** Draft/scheduled → preparing: queues the job that builds the recipient list. */
    public function start(EmailCampaign $c): void
    {
        $c->update([
            'status' => EmailCampaign::PREPARING, 'status_note' => null,
            'scheduled_at' => null, 'started_at' => now(), 'completed_at' => null,
        ]);

        PrepareEmailCampaign::dispatch($c->id)->onQueue(self::QUEUE);
    }

    public function schedule(EmailCampaign $c, \DateTimeInterface $at): void
    {
        $c->update(['status' => EmailCampaign::SCHEDULED, 'scheduled_at' => $at, 'status_note' => null]);
    }

    public function unschedule(EmailCampaign $c): void
    {
        $c->update(['status' => EmailCampaign::DRAFT, 'scheduled_at' => null]);
    }

    /** Builds the recipient rows (idempotent) and hands the campaign to the senders. */
    public function prepare(EmailCampaign $c): void
    {
        $filters = $c->filters ?? [];
        $aud     = $c->audience;

        $stillPreparing = fn () => EmailCampaign::whereKey($c->id)->where('status', EmailCampaign::PREPARING)->exists();

        if (in_array($aud, ['users', 'both'], true)) {
            $this->usersQuery($filters)->select(['id', 'name', 'email'])
                ->chunkById(1000, function ($users) use ($c, $stillPreparing) {
                    if (! $stillPreparing()) {
                        return false;
                    }
                    $this->insertRecipients($c, $users->map(fn ($u) => [
                        'user_id' => $u->id, 'email' => $u->email, 'name' => $u->name,
                    ])->all());
                });
        }

        if (in_array($aud, ['subscribers', 'both'], true)) {
            $this->subscribersQuery(true, $aud === 'both')->select(['id', 'email'])
                ->chunkById(1000, function ($subs) use ($c, $stillPreparing) {
                    if (! $stillPreparing()) {
                        return false;
                    }
                    $this->insertRecipients($c, $subs->map(fn ($s) => [
                        'user_id' => null, 'email' => $s->email, 'name' => null,
                    ])->all());
                });
        }

        if (! $stillPreparing()) {
            return; // cancelled while building
        }

        $total = Recipient::where('campaign_id', $c->id)->count();

        if ($total === 0) {
            $c->update([
                'status' => EmailCampaign::FAILED, 'completed_at' => now(),
                'status_note' => 'No recipients matched this audience (or all of them have unsubscribed).',
            ]);
            return;
        }

        $c->update([
            'status' => EmailCampaign::SENDING, 'total_recipients' => $total,
            'skipped_count' => $this->suppressedCount($aud, $filters),
        ]);

        $this->dispatchPending($c->fresh());
    }

    private function insertRecipients(EmailCampaign $c, array $rows): void
    {
        $now = now();
        $batch = [];
        foreach ($rows as $row) {
            $email = strtolower(trim($row['email']));
            if ($email === '') {
                continue;
            }
            $batch[] = [
                'campaign_id' => $c->id, 'user_id' => $row['user_id'], 'email' => $email,
                'name' => $row['name'], 'token' => \Illuminate\Support\Str::random(32),
                'status' => Recipient::PENDING, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ($batch) {
            // unique (campaign_id, email): a re-run or a duplicate address is silently ignored
            DB::table('email_campaign_recipients')->insertOrIgnore($batch);
        }
    }

    /**
     * Queues one SendCampaignEmail per pending recipient, staggered so the
     * campaign sends at throttle_per_minute. Safe to call twice: a job whose
     * recipient is no longer pending does nothing.
     */
    public function dispatchPending(EmailCampaign $c): void
    {
        $perMinute = max(1, (int) $c->throttle_per_minute);
        $i = 0;

        Recipient::where('campaign_id', $c->id)->where('status', Recipient::PENDING)
            ->select('id')
            ->chunkById(500, function ($chunk) use (&$i, $perMinute) {
                $jobs = [];
                foreach ($chunk as $r) {
                    $jobs[] = (new SendCampaignEmail($r->id))->delay(now()->addSeconds(intdiv($i * 60, $perMinute)));
                    $i++;
                }
                Queue::bulk($jobs, '', self::QUEUE);
            });
    }

    public function pause(EmailCampaign $c, ?string $note = null): bool
    {
        // Queued jobs notice the status and exit without touching their recipient.
        return EmailCampaign::whereKey($c->id)->where('status', EmailCampaign::SENDING)
            ->update(['status' => EmailCampaign::PAUSED, 'status_note' => $note, 'resume_at' => null, 'updated_at' => now()]) > 0;
    }

    /**
     * The mail provider said "too many messages" (an hourly cap). Pause the whole campaign and let the
     * scheduler resume it later, instead of failing emails that were perfectly fine. The wait grows
     * if the limit trips again: 20, 40, then 60 minutes (the provider counts a rolling hour).
     */
    public function pauseForRateLimit(EmailCampaign $c): bool
    {
        $hits    = (int) EmailCampaign::whereKey($c->id)->value('rate_limit_hits');
        $minutes = min(60, 20 * ($hits + 1));
        $resume  = now()->addMinutes($minutes);
        $local   = $resume->copy()->timezone(config('app.display_timezone', 'UTC'))->format('H:i');

        $paused = EmailCampaign::whereKey($c->id)->where('status', EmailCampaign::SENDING)->update([
            'status'          => EmailCampaign::PAUSED,
            'status_note'     => "The mail provider's sending limit was reached (too many emails in an hour). Nothing was lost: sending resumes by itself at about {$local} ({$minutes} min).",
            'resume_at'       => $resume,
            'rate_limit_hits' => $hits + 1,
            'updated_at'      => now(),
        ]) > 0;

        if ($paused) {
            \Illuminate\Support\Facades\Log::warning('Email campaign paused: provider rate limit', ['campaign' => $c->id, 'resume_in_min' => $minutes]);
        }

        return $paused;
    }

    /** Resumes campaigns whose rate-limit pause has run out. Returns how many were resumed. */
    public function resumeDue(): int
    {
        $n = 0;
        EmailCampaign::where('status', EmailCampaign::PAUSED)->whereNotNull('resume_at')->where('resume_at', '<=', now())
            ->each(function (EmailCampaign $c) use (&$n) {
                if ($this->resume($c)) {
                    $n++;
                }
            });

        return $n;
    }

    public function resume(EmailCampaign $c): bool
    {
        $ok = EmailCampaign::whereKey($c->id)->where('status', EmailCampaign::PAUSED)
            ->update(['status' => EmailCampaign::SENDING, 'status_note' => null, 'resume_at' => null, 'updated_at' => now()]) > 0;

        if ($ok) {
            $this->dispatchPending($c->fresh());
            $this->maybeComplete($c->fresh());
        }

        return $ok;
    }

    public function cancel(EmailCampaign $c): bool
    {
        $ok = EmailCampaign::whereKey($c->id)
            ->whereIn('status', [EmailCampaign::PREPARING, EmailCampaign::SENDING, EmailCampaign::PAUSED, EmailCampaign::SCHEDULED])
            ->update(['status' => EmailCampaign::CANCELLED, 'completed_at' => now(), 'updated_at' => now()]) > 0;

        if ($ok) {
            // Emails already handed to SMTP stay sent; everything still waiting is dropped.
            $skipped = Recipient::where('campaign_id', $c->id)->where('status', Recipient::PENDING)
                ->update(['status' => Recipient::SKIPPED, 'error' => 'Campaign cancelled', 'updated_at' => now()]);
            if ($skipped) {
                EmailCampaign::whereKey($c->id)->increment('skipped_count', $skipped);
            }
        }

        return $ok;
    }

    /** Puts failed recipients (except permanent bounces) back in the queue. Returns how many. */
    public function retryFailed(EmailCampaign $c): int
    {
        if (! in_array($c->status, [EmailCampaign::COMPLETED, EmailCampaign::PAUSED, EmailCampaign::SENDING], true)) {
            return 0;
        }

        $n = Recipient::where('campaign_id', $c->id)->where('status', Recipient::FAILED)
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('email_suppressions')
                ->whereColumn('email_suppressions.email', 'email_campaign_recipients.email'))
            ->update(['status' => Recipient::PENDING, 'attempts' => 0, 'error' => null, 'updated_at' => now()]);

        if ($n === 0) {
            return 0;
        }

        EmailCampaign::whereKey($c->id)->decrement('failed_count', $n);

        if ($c->status === EmailCampaign::COMPLETED) {
            EmailCampaign::whereKey($c->id)->update(['status' => EmailCampaign::SENDING, 'completed_at' => null]);
        }
        if ($c->status !== EmailCampaign::PAUSED) {
            $this->dispatchPending($c->fresh());
        }

        return $n;
    }

    /** Marks the campaign completed once nothing is waiting or in flight. */
    public function maybeComplete(EmailCampaign $c): void
    {
        $waiting = Recipient::where('campaign_id', $c->id)
            ->whereIn('status', [Recipient::PENDING, Recipient::SENDING])->exists();

        if (! $waiting) {
            EmailCampaign::whereKey($c->id)->where('status', EmailCampaign::SENDING)
                ->update(['status' => EmailCampaign::COMPLETED, 'completed_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Called when a send ends in a non-bounce failure: stops the campaign if SMTP looks broken. */
    public function autoPauseOnFailureStreak(EmailCampaign $c): void
    {
        if ($c->fresh()->failed_count < 20) {
            return;
        }

        $recent = Recipient::where('campaign_id', $c->id)->whereIn('status', [Recipient::SENT, Recipient::FAILED])
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(20)->get(['status', 'error']);

        $allSoftFailures = $recent->count() === 20
            && $recent->every(fn ($r) => $r->status === Recipient::FAILED && ! str_starts_with((string) $r->error, '[bounce]'));

        if ($allSoftFailures) {
            $this->pause($c, 'Auto-paused: the last 20 emails all failed. Check the SMTP settings, then resume.');
        }
    }

    /**
     * True when the mail PROVIDER is limiting the whole sender (hourly / daily cap, throttling),
     * as opposed to a problem with one recipient. Deliberately narrow: "mailbox full" or a
     * greylisted address must not pause everyone.
     */
    public function isRateLimited(Throwable $e): bool
    {
        return (bool) preg_match(
            '/too many (messages|emails|mails|connections|recipients)|sending (limit|quota)|(hourly|daily) (sending |email )?(limit|quota)|rate.?limit|limit (has been )?(exceeded|reached)|exceeded (the |your )?(daily|hourly|sending|maximum)|throttl|\b4\.7\.\d\b|\b5\.4\.5\b/i',
            $e->getMessage(),
        );
    }

    /** True for errors where retrying can never help (bad / non-existent mailbox). */
    public function isPermanentFailure(Throwable $e): bool
    {
        if ($e instanceof \Symfony\Component\Mime\Exception\RfcComplianceException) {
            return true;
        }

        return (bool) preg_match(
            '/\b5\.1\.[0-2]\b|user unknown|no such user|mailbox (not found|unavailable|does not exist)|recipient (address )?rejected|invalid recipient|address rejected|does not exist/i',
            $e->getMessage(),
        );
    }

    /** Releases recipients stuck "sending" (worker died mid-send) and re-queues them. */
    public function recoverStuck(int $minutes = 15): int
    {
        $ids = Recipient::where('status', Recipient::SENDING)
            ->where('claimed_at', '<', now()->subMinutes($minutes))
            ->whereHas('campaign', fn ($q) => $q->where('status', EmailCampaign::SENDING))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        Recipient::whereIn('id', $ids)->update(['status' => Recipient::PENDING, 'updated_at' => now()]);
        foreach ($ids->chunk(500) as $chunk) {
            Queue::bulk($chunk->map(fn ($id) => new SendCampaignEmail($id))->all(), '', self::QUEUE);
        }

        return $ids->count();
    }
}
