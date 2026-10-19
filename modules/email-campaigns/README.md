# Email Campaigns

A complete bulk-email system for sending to registered users and newsletter
subscribers, the kind usually bought as a SaaS, built into the product.

## What it does

- **Audiences:** registered users, newsletter subscribers, or both (never the
  same address twice), filtered by plan (free / premium), verified email,
  signup date, or "skip everyone campaign X already emailed" for follow-ups.
- **Lifecycle:** draft → scheduled → preparing → sending → completed, with
  pause, resume, cancel and retry-failed at any point.
- **Throttling:** one queued job per recipient, staggered to the campaign's
  emails-per-minute setting, on a dedicated `campaigns` queue so a large send
  can never delay video searches.
- **Tracking:** an open pixel, plus click tracking through **signed** redirect
  URLs so the redirect can't be used as an open redirect.
- **Compliance:** a global do-not-email list, checked when the audience is
  built *and* again right before each send. Also RFC 8058 one-click
  unsubscribe headers (Gmail / Yahoo's own unsubscribe button).

## Design decisions worth reading

| Problem | Solution | Where |
|---|---|---|
| Two workers could send the same email | An atomic claim: `UPDATE … WHERE status = 'pending'`, and only the winner sends | `SendCampaignEmail::handle` |
| A worker dies mid-send and the recipient is stuck "sending" forever | The every-minute tick re-queues anything claimed more than 15 minutes ago | `EmailCampaignService::recoverStuck` |
| The mail provider's hourly cap would fail thousands of good emails | Rate-limit errors are recognised, the email goes back to pending, and the campaign pauses and resumes itself after 20, 40, then 60 minutes | `pauseForRateLimit`, `resumeDue` |
| One bad mailbox must not pause everyone, but broken SMTP should stop the send | Permanent bounces (5.1.x, "user unknown") are suppressed for good, and 20 soft failures in a row auto-pause the campaign | `isPermanentFailure`, `autoPauseOnFailureStreak` |
| Mail security scanners pre-fetch links and would unsubscribe people | GET shows a confirm page and only POST unsubscribes (which is also what the one-click button sends) | `EmailCampaignTrackingController::unsubscribe` |
| Someone unsubscribes while a campaign is mid-send | Suppression is re-checked just before each send | `SendCampaignEmail::handle` |
| Admin-typed HTML with merge tags | Names are HTML-escaped when merged into the body, and only http(s) links are rewritten for tracking | `EmailCampaignRenderer` |

## Files

```
app/
  Services/EmailCampaignService.php        audience building + the whole lifecycle
  Services/EmailCampaignRenderer.php       merge tags, tracked links, unsubscribe URLs
  Jobs/PrepareEmailCampaign.php            builds the recipient list (idempotent)
  Jobs/SendCampaignEmail.php               sends one email, retries, bounce handling
  Console/Commands/TickEmailCampaigns.php  every minute: start, resume, rescue, complete
  Mail/CampaignMail.php                    the mailable (List-Unsubscribe headers)
  Models/EmailCampaign*.php, EmailSuppression.php
  Http/Controllers/Home/EmailCampaignTrackingController.php   open / click / unsubscribe
database/migrations/                       3 migrations
resources/views/                           email template + unsubscribe pages
routes/excerpt.php                         how it is wired in
tests/Feature/EmailCampaignTest.php        28 tests
```

The admin UI (composer, audience preview, live progress, CSV export) and its
HTTP tests are part of the private codebase.
