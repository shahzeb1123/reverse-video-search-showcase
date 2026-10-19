<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignEmail;
use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient as Recipient;
use App\Models\EmailSuppression;
use App\Models\NewsletterSubscriber;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EmailCampaignRenderer;
use App\Services\EmailCampaignService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Email campaigns: who gets mailed, that every outcome is counted, and that
 * nobody who opted out (or whose mailbox does not exist) is ever mailed again.
 * QUEUE_CONNECTION=sync in phpunit.xml, so queued jobs run inline.
 */
class EmailCampaignTest extends TestCase
{
    use RefreshDatabase;

    private EmailCampaignService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmailCampaignService::class);
    }

    private function campaign(array $attrs = []): EmailCampaign
    {
        return EmailCampaign::create(array_merge([
            'name' => 'Launch', 'subject' => 'Hi {{first_name}}', 'body' => '<p>Hello {{name}}</p><a href="https://example.com/offer">Offer</a>',
            'audience' => 'users', 'filters' => ['plan' => 'all'], 'throttle_per_minute' => 600,
        ], $attrs))->fresh();   // fresh(): pick up the column defaults (tracking on)
    }

    private function premium(User $user): User
    {
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Basic', 'slug' => 'basic-' . Str::random(5), 'billing_cycle' => 'monthly',
            'price' => 7.99, 'currency' => 'USD', 'search_quota' => 150, 'quota_period' => 'monthly', 'is_active' => 1,
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);

        return $user;
    }

    private function recipientFor(EmailCampaign $c, string $email = 'a@example.com'): Recipient
    {
        return Recipient::create(['campaign_id' => $c->id, 'email' => $email, 'name' => 'Ann Lee', 'status' => 'pending']);
    }

    private function runJob(int $recipientId, int $attempt = 1): void
    {
        $job = new SendCampaignEmail($recipientId);
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('release')->byDefault();
        $job->setJob($queueJob);
        $job->handle($this->service);
    }

    private function failMailWith(string $message): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException($message));
    }

    // ── audience ─────────────────────────────────────────────────────────

    public function test_audience_respects_filters_inactive_users_and_the_do_not_email_list(): void
    {
        User::factory()->count(2)->create();
        User::factory()->unverified()->create();
        User::factory()->create(['is_active' => false]);
        $paying = $this->premium(User::factory()->create());
        $blocked = User::factory()->create();
        EmailSuppression::add($blocked->email);

        // 4 eligible: 2 + unverified + premium; the inactive and the suppressed one are out.
        $this->assertSame(4, $this->service->audienceCounts('users')['total']);
        $this->assertSame(1, $this->service->audienceCounts('users')['suppressed']);
        $this->assertSame(3, $this->service->audienceCounts('users', ['verified_only' => true])['total']);
        $this->assertSame(1, $this->service->audienceCounts('users', ['plan' => 'premium'])['total']);
        $this->assertSame(3, $this->service->audienceCounts('users', ['plan' => 'free'])['total']);
        $this->assertTrue($this->service->usersQuery(['plan' => 'premium'])->where('id', $paying->id)->exists());
    }

    public function test_a_follow_up_campaign_can_skip_everyone_an_earlier_campaign_already_has(): void
    {
        $sent = User::factory()->count(3)->create();                 // got the first campaign
        $missed = User::factory()->unverified()->count(2)->create();  // were filtered out of it
        $paying = $this->premium(User::factory()->create());

        $first = $this->campaign(['status' => 'sending', 'filters' => ['plan' => 'free', 'verified_only' => true]]);
        foreach ($sent as $u) {
            Recipient::create(['campaign_id' => $first->id, 'user_id' => $u->id, 'email' => strtolower($u->email), 'status' => 'pending']);   // even still waiting counts
        }

        $filters = ['plan' => 'all', 'verified_only' => false, 'exclude_campaign_id' => $first->id];
        $emails = $this->service->usersQuery($filters)->pluck('email')->map('strtolower')->all();

        $this->assertEqualsCanonicalizing(
            array_merge($missed->pluck('email')->map('strtolower')->all(), [strtolower($paying->email)]),
            $emails,
            'exactly the people the first campaign did not include',
        );
        $this->assertSame(3, $this->service->audienceCounts('users', $filters)['total']);
        $this->assertSame(6, $this->service->audienceCounts('users', ['plan' => 'all'])['total']);
    }

    public function test_both_audience_never_mails_the_same_address_twice(): void
    {
        $user = User::factory()->create(['email' => 'both@example.com']);
        NewsletterSubscriber::create(['email' => 'both@example.com', 'status' => 'active', 'subscribed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'only-sub@example.com', 'status' => 'active', 'subscribed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'gone@example.com', 'status' => 'unsubscribed', 'subscribed_at' => now()]);

        $c = $this->campaign(['audience' => 'both']);
        Mail::fake();
        $this->service->start($c);

        $this->assertEqualsCanonicalizing(['both@example.com', 'only-sub@example.com'], Recipient::pluck('email')->all());
        $this->assertSame($user->id, Recipient::where('email', 'both@example.com')->value('user_id'));
    }

    // ── end to end ───────────────────────────────────────────────────────

    public function test_a_campaign_sends_to_everyone_and_counts_it(): void
    {
        User::factory()->count(3)->create();
        Mail::fake();

        $c = $this->campaign();
        $this->service->start($c);

        Mail::assertSent(CampaignMail::class, 3);
        $c->refresh();
        $this->assertSame('completed', $c->status);
        $this->assertSame(3, $c->total_recipients);
        $this->assertSame(3, $c->sent_count);
        $this->assertSame(0, $c->failed_count);
        $this->assertSame(3, Recipient::where('status', 'sent')->count());
        $this->assertNotNull($c->completed_at);
    }

    public function test_an_empty_audience_fails_cleanly_instead_of_hanging(): void
    {
        Mail::fake();
        $c = $this->campaign();
        $this->service->start($c);

        $c->refresh();
        $this->assertSame('failed', $c->status);
        $this->assertStringContainsString('No recipients', $c->status_note);
        Mail::assertNothingSent();
    }

    // ── failures ─────────────────────────────────────────────────────────

    public function test_a_temporary_smtp_error_is_retried_then_counted_as_failed(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 1]);
        $r = $this->recipientFor($c);
        $this->failMailWith('Connection timed out');

        $this->runJob($r->id, 1);   // attempt 1: goes back to pending
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertSame(0, $c->fresh()->failed_count);

        $this->runJob($r->id, 3);   // final attempt: failed
        $r->refresh();
        $this->assertSame('failed', $r->status);
        $this->assertStringContainsString('timed out', $r->error);
        $this->assertSame(1, $c->fresh()->failed_count);
        $this->assertSame('completed', $c->fresh()->status);
        $this->assertFalse(EmailSuppression::isSuppressed('a@example.com'), 'a timeout is not a bad address');
    }

    public function test_a_nonexistent_mailbox_fails_at_once_and_is_never_mailed_again(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 1]);
        $r = $this->recipientFor($c, 'nobody@example.com');
        $this->failMailWith('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown"');

        $this->runJob($r->id, 1);

        $this->assertSame('failed', $r->fresh()->status);
        $this->assertStringStartsWith('[bounce]', $r->fresh()->error);
        $this->assertTrue(EmailSuppression::isSuppressed('nobody@example.com'));
        $this->assertSame('bounced', EmailSuppression::first()->reason);
        $this->assertSame(0, $this->service->retryFailed($c->fresh()), 'bounces are not retried');
    }

    public function test_retry_failed_puts_only_recoverable_failures_back_and_finishes_them(): void
    {
        $c = $this->campaign(['status' => 'completed', 'total_recipients' => 2, 'failed_count' => 2]);
        $soft = Recipient::create(['campaign_id' => $c->id, 'email' => 'soft@example.com', 'status' => 'failed', 'error' => 'timeout']);
        Recipient::create(['campaign_id' => $c->id, 'email' => 'hard@example.com', 'status' => 'failed', 'error' => '[bounce] unknown']);
        EmailSuppression::add('hard@example.com', 'bounced');
        Mail::fake();

        $this->assertSame(1, $this->service->retryFailed($c));

        $this->assertSame('sent', $soft->fresh()->status);
        $c->refresh();
        $this->assertSame(1, $c->sent_count);
        $this->assertSame(1, $c->failed_count, 'only the bounce is still failed');
        $this->assertSame('completed', $c->status);
    }

    // ── provider hourly limit: pause, then resume by itself ──────────────

    private const HOURLY_LIMIT_ERROR = 'Expected response code "354" but got code "554", with message "554 5.7.1 <DATA>: Data command rejected: Reject: too many messages from sender in last 60 minutes".';

    public function test_the_providers_hourly_limit_pauses_the_campaign_instead_of_failing_emails(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 3]);
        $ids = collect(['a', 'b', 'c'])->map(fn ($n) => $this->recipientFor($c, "{$n}@example.com")->id);
        $this->failMailWith(self::HOURLY_LIMIT_ERROR);

        $this->runJob($ids[0], 3);   // even on the LAST attempt it is not a failure

        $c->refresh();
        $this->assertSame('paused', $c->status);
        $this->assertSame(0, $c->failed_count, 'nobody is marked failed');
        $this->assertSame(1, $c->rate_limit_hits);
        $this->assertEqualsWithDelta(20, now()->diffInMinutes($c->resume_at, false), 1);
        $this->assertStringContainsString('resumes by itself', $c->status_note);

        $r = Recipient::find($ids[0]);
        $this->assertSame('pending', $r->status, 'the email is waiting, not lost');
        $this->assertSame(0, $r->attempts, 'and the try does not count against it');
        $this->assertStringContainsString('too many messages', $r->error);

        // Jobs that were already queued exit quietly while paused.
        $this->runJob($ids[1], 1);
        $this->assertSame('pending', Recipient::find($ids[1])->status);
        $this->assertSame(0, $c->fresh()->failed_count);
    }

    public function test_the_scheduler_resumes_a_rate_limited_campaign_when_the_wait_is_over_and_sends_everything(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 2]);
        $this->recipientFor($c, 'a@example.com');
        $this->recipientFor($c, 'b@example.com');
        $this->service->pauseForRateLimit($c);

        Mail::fake();
        $this->artisan('email-campaigns:tick')->assertSuccessful();
        $this->assertSame('paused', $c->fresh()->status, 'too early: still waiting');
        Mail::assertNothingSent();

        $c->update(['resume_at' => now()->subMinute()]);
        $this->artisan('email-campaigns:tick')->assertSuccessful();

        $c->refresh();
        Mail::assertSent(CampaignMail::class, 2);
        $this->assertSame('completed', $c->status);
        $this->assertSame(2, $c->sent_count);
        $this->assertNull($c->resume_at);
    }

    public function test_repeated_rate_limits_wait_longer_each_time(): void
    {
        $c = $this->campaign(['status' => 'sending']);

        foreach ([20, 40, 60, 60] as $expected) {
            $c->refresh()->update(['status' => 'sending']);
            $this->service->pauseForRateLimit($c);
            $this->assertEqualsWithDelta($expected, now()->diffInMinutes($c->fresh()->resume_at, false), 1);
        }
    }

    public function test_only_sender_wide_limits_pause_the_campaign_not_one_bad_recipient(): void
    {
        $svc = $this->service;
        $limit = fn (string $m) => $svc->isRateLimited(new \RuntimeException($m));

        $this->assertTrue($limit(self::HOURLY_LIMIT_ERROR));
        $this->assertTrue($limit('550 5.4.5 Daily user sending quota exceeded'));
        $this->assertTrue($limit('421 4.7.0 Try again later, rate limit exceeded'));
        $this->assertTrue($limit('You have exceeded your hourly sending limit'));

        $this->assertFalse($limit('452 4.2.2 The email account that you tried to reach is over quota'));
        $this->assertFalse($limit('550 5.1.1 User unknown'));
        $this->assertFalse($limit('Connection timed out'));
        $this->assertFalse($limit('554 5.7.1 Message rejected as spam'));
    }

    public function test_a_manual_pause_or_resume_cancels_the_automatic_resume(): void
    {
        $c = $this->campaign(['status' => 'sending']);
        $this->recipientFor($c);
        $this->service->pauseForRateLimit($c);
        $this->assertNotNull($c->fresh()->resume_at);

        Mail::fake();
        $this->assertTrue($this->service->resume($c->fresh()));
        $this->assertNull($c->fresh()->resume_at);

        $this->service->pauseForRateLimit($c->fresh());          // trips again
        $c->refresh()->update(['status' => 'sending']);
        $this->assertTrue($this->service->pause($c->fresh(), 'by admin'));
        $this->assertNull($c->fresh()->resume_at, 'an admin pause stays paused until an admin resumes');
    }

    public function test_the_campaign_pauses_itself_when_smtp_is_clearly_broken(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 25]);
        $this->failMailWith('Authentication failed');

        $ids = collect(range(0, 24))->map(fn ($i) => $this->recipientFor($c, "u{$i}@example.com")->id);
        foreach ($ids as $id) {
            $this->runJob($id, 3);
        }

        $c->refresh();
        $this->assertSame('paused', $c->status);
        $this->assertStringContainsString('Auto-paused', $c->status_note);
        // The rest of the list was NOT burned through once the streak was detected
        // (jobs that run while paused leave their recipient pending).
        $this->assertGreaterThan(0, Recipient::where('campaign_id', $c->id)->where('status', 'pending')->count());
    }

    // ── pause / resume / cancel ──────────────────────────────────────────

    public function test_pause_stops_sending_and_resume_finishes_the_rest(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 2]);
        $a = $this->recipientFor($c, 'a@example.com');
        $b = $this->recipientFor($c, 'b@example.com');
        Mail::fake();

        $this->assertTrue($this->service->pause($c));
        $this->runJob($a->id);                       // a queued job that fires while paused
        $this->assertSame('pending', $a->fresh()->status);
        Mail::assertNothingSent();

        $this->assertTrue($this->service->resume($c->fresh()));
        Mail::assertSent(CampaignMail::class, 2);
        $this->assertSame('completed', $c->fresh()->status);
        $this->assertSame(2, $c->fresh()->sent_count);
    }

    public function test_cancel_drops_waiting_emails_but_keeps_the_delivered_count(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 3, 'sent_count' => 1]);
        Recipient::create(['campaign_id' => $c->id, 'email' => 'done@example.com', 'status' => 'sent']);
        $w1 = $this->recipientFor($c, 'w1@example.com');
        $this->recipientFor($c, 'w2@example.com');
        Mail::fake();

        $this->assertTrue($this->service->cancel($c));
        $this->runJob($w1->id);

        Mail::assertNothingSent();
        $c->refresh();
        $this->assertSame('cancelled', $c->status);
        $this->assertSame(1, $c->sent_count);
        $this->assertSame(2, $c->skipped_count);
        $this->assertSame(2, Recipient::where('status', 'skipped')->count());
    }

    public function test_someone_who_unsubscribes_mid_campaign_is_skipped(): void
    {
        $c = $this->campaign(['status' => 'sending', 'total_recipients' => 1]);
        $r = $this->recipientFor($c);
        EmailSuppression::add('a@example.com');
        Mail::fake();

        $this->runJob($r->id);

        Mail::assertNothingSent();
        $this->assertSame('skipped', $r->fresh()->status);
        $this->assertSame('completed', $c->fresh()->status);
    }

    // ── throttling / scheduling / recovery ───────────────────────────────

    public function test_sends_are_spread_out_to_respect_the_per_minute_limit(): void
    {
        Queue::fake();
        $c = $this->campaign(['status' => 'sending', 'throttle_per_minute' => 60]);
        foreach (range(1, 130) as $i) {
            $this->recipientFor($c, "t{$i}@example.com");
        }

        $this->service->dispatchPending($c);

        Queue::assertPushed(SendCampaignEmail::class, 130);
        $delays = Queue::pushed(SendCampaignEmail::class)->map(fn ($j) => (int) now()->diffInSeconds($j->delay, false))->sort()->values();
        $this->assertLessThanOrEqual(2, $delays->first(), 'the first emails go out immediately');
        $this->assertGreaterThanOrEqual(115, $delays->last(), '130 emails at 60/min take just over 2 minutes');
        // never more than ~60 in any one-minute bucket
        $this->assertLessThanOrEqual(61, $delays->groupBy(fn ($d) => intdiv($d, 60))->map->count()->max());
    }

    public function test_the_scheduler_tick_starts_due_campaigns_only(): void
    {
        User::factory()->create();
        Mail::fake();
        $due    = $this->campaign(['name' => 'due', 'status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
        $future = $this->campaign(['name' => 'future', 'status' => 'scheduled', 'scheduled_at' => now()->addHour()]);

        $this->artisan('email-campaigns:tick')->assertSuccessful();

        $this->assertSame('completed', $due->fresh()->status);
        $this->assertSame('scheduled', $future->fresh()->status);
        Mail::assertSent(CampaignMail::class, 1);
    }

    public function test_recipients_stuck_in_sending_are_rescued(): void
    {
        Queue::fake();
        $c = $this->campaign(['status' => 'sending']);
        $stuck = $this->recipientFor($c);
        $stuck->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(30)]);
        $fresh = $this->recipientFor($c, 'b@example.com');
        $fresh->update(['status' => 'sending', 'claimed_at' => now()->subMinute()]);

        $this->assertSame(1, $this->service->recoverStuck());

        $this->assertSame('pending', $stuck->fresh()->status);
        $this->assertSame('sending', $fresh->fresh()->status);
        Queue::assertPushed(SendCampaignEmail::class, 1);
    }

    // ── email content ────────────────────────────────────────────────────

    public function test_merge_tags_are_personalised_and_html_escaped(): void
    {
        $c = $this->campaign(['subject' => 'Hi {{ first_name }}!', 'body' => '<p>{{name}} / {{email}}</p>']);
        $r = new Recipient(['email' => 'x@example.com', 'name' => '<b>Eve</b> Adams', 'token' => 'tok']);
        $renderer = app(EmailCampaignRenderer::class);

        $this->assertSame('Hi <b>Eve</b>!', $renderer->subject($c, $r), 'subject is plain text, not HTML');
        $body = $renderer->body($c, $r);
        $this->assertStringContainsString('&lt;b&gt;Eve&lt;/b&gt; Adams', $body);
        $this->assertStringNotContainsString('<b>Eve</b>', $body);
        $this->assertSame('there', (new Recipient(['name' => null]))->firstName());
    }

    public function test_links_are_tracked_unsubscribe_is_not_and_the_headers_are_set(): void
    {
        $c = $this->campaign(['body' => '<a href="https://example.com/a?x=1&amp;y=2">A</a> <a href="mailto:hi@example.com">M</a> <a href="{{unsubscribe_url}}">U</a>']);
        $r = $this->recipientFor($c);
        $html = app(EmailCampaignRenderer::class)->body($c, $r);

        $this->assertSame(1, preg_match_all('#/e/c/' . $r->token . '#', $html), 'only the http link is tracked');
        $this->assertStringContainsString('mailto:hi@example.com', $html);
        $this->assertStringContainsString('/email/unsubscribe/' . $r->token, $html);

        $mail = new CampaignMail($c, $r);
        $headers = $mail->headers()->text;
        $this->assertStringContainsString('/email/unsubscribe/' . $r->token, $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertStringContainsString('/e/o/' . $r->token, $mail->render());
    }

    public function test_tracking_can_be_switched_off(): void
    {
        $c = $this->campaign(['track_clicks' => false, 'track_opens' => false]);
        $r = $this->recipientFor($c);
        $html = (new CampaignMail($c, $r))->render();

        $this->assertStringNotContainsString('/e/c/', $html);
        $this->assertStringNotContainsString('/e/o/', $html);
    }

    // ── public endpoints ─────────────────────────────────────────────────

    public function test_opens_and_clicks_count_each_person_once(): void
    {
        $c = $this->campaign();
        $r = $this->recipientFor($c);

        $this->get(route('email.track.open', $r->token))->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get(route('email.track.open', $r->token))->assertOk();
        $this->assertSame(1, $c->fresh()->opened_count);
        $this->assertSame(2, $r->fresh()->open_count);

        $url = URL::signedRoute('email.track.click', ['token' => $r->token, 'u' => rtrim(strtr(base64_encode('https://example.com/offer'), '+/', '-_'), '=')]);
        $this->get($url)->assertRedirect('https://example.com/offer');
        $this->get($url)->assertRedirect('https://example.com/offer');
        $this->assertSame(1, $c->fresh()->clicked_count);
        $this->assertSame(2, $r->fresh()->click_count);
    }

    public function test_the_click_redirect_cannot_be_abused_as_an_open_redirect(): void
    {
        $r = $this->recipientFor($this->campaign());
        $u = rtrim(strtr(base64_encode('https://evil.example/phish'), '+/', '-_'), '=');

        // unsigned or tampered link → refused
        $this->get(route('email.track.click', ['token' => $r->token, 'u' => $u]))->assertForbidden();

        // signed but not an http(s) URL → refused
        $bad = rtrim(strtr(base64_encode('javascript:alert(1)'), '+/', '-_'), '=');
        $this->get(URL::signedRoute('email.track.click', ['token' => $r->token, 'u' => $bad]))->assertNotFound();
    }

    public function test_unsubscribe_needs_a_confirmation_post_and_updates_every_list(): void
    {
        $c = $this->campaign();
        $r = $this->recipientFor($c, 'leaver@example.com');
        NewsletterSubscriber::create(['email' => 'leaver@example.com', 'status' => 'active', 'subscribed_at' => now()]);
        $url = URL::signedRoute('email.unsubscribe', ['token' => $r->token]);

        // A mail scanner pre-fetching the link must NOT unsubscribe anyone.
        $this->get($url)->assertOk()->assertSee('Yes, unsubscribe me');
        $this->assertFalse(EmailSuppression::isSuppressed('leaver@example.com'));

        // One-click POST (Gmail/Yahoo) carries no CSRF token.
        $this->post($url)->assertOk()->assertSee('been unsubscribed');
        $this->assertTrue(EmailSuppression::isSuppressed('leaver@example.com'));
        $this->assertSame('unsubscribed', NewsletterSubscriber::where('email', 'leaver@example.com')->value('status'));
        $this->assertSame(1, $c->fresh()->unsubscribed_count);

        $this->post($url)->assertOk();
        $this->assertSame(1, $c->fresh()->unsubscribed_count, 'counted once');
    }

    public function test_an_unsubscribe_link_with_a_bad_signature_is_refused(): void
    {
        $r = $this->recipientFor($this->campaign(), 'safe@example.com');

        $this->post(route('email.unsubscribe', $r->token))->assertForbidden();
        $this->get(route('email.unsubscribe', $r->token) . '?signature=nope')->assertForbidden();
        $this->assertFalse(EmailSuppression::isSuppressed('safe@example.com'));
    }

    public function test_the_old_newsletter_unsubscribe_also_blocks_campaigns(): void
    {
        NewsletterSubscriber::create(['email' => 'old@example.com', 'status' => 'active', 'subscribed_at' => now()]);

        $this->get(URL::signedRoute('newsletter.unsubscribe', ['email' => 'old@example.com']))->assertOk();

        $this->assertTrue(EmailSuppression::isSuppressed('old@example.com'));
    }
}
