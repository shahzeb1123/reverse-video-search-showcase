---
name: email-campaigns-module
description: Admin Email Campaigns module (bulk email to registered users + newsletter subscribers via a dedicated queue) — architecture, behaviour rules, deploy requirements
metadata:
  type: project
---

Built at the owner's request ("a complete email campaign module like
professional companies have"). Separate from the older Newsletter module; the
old newsletter unsubscribe link now ALSO writes to the new suppression list.

## How it works
- Admin → Email Campaigns (permissions `email-campaigns.view|edit|send|delete`).
  Compose with merge tags `{{name}} {{first_name}} {{email}} {{app_name}} {{unsubscribe_url}}`,
  preheader, from-name, reply-to, audience (users / subscribers / both) + filters
  (plan free/premium, verified only, signup date range), live recipient count,
  preview, test send, schedule (admin types local time, stored UTC), send now,
  pause/resume/cancel, retry failed, duplicate, CSV export, live progress.
- Tables: `email_campaigns`, `email_campaign_recipients` (unique campaign+email,
  public `token`), `email_suppressions` (global do-not-email list).
- Flow: `EmailCampaignService::start` → job `PrepareEmailCampaign` builds
  recipient rows (dedupe, suppressed excluded) → `dispatchPending` queues one
  `SendCampaignEmail` job per recipient on queue **`campaigns`**, staggered by
  `throttle_per_minute` via job delay. Each job: atomic claim (pending → sending),
  re-checks suppression, sends `CampaignMail`, 3 tries (60 s / 300 s backoff).
  Counters on the campaign; it completes itself when nothing is pending/sending.
- Pause = jobs that fire while paused leave their recipient pending and exit;
  resume re-queues pending. Cancel marks pending as skipped.
- Permanent errors (5.1.x, "user unknown", bad RFC address) fail at once with
  `[bounce]` and the address is auto-added to the suppression list. 20
  non-bounce failures in a row ⇒ the campaign auto-pauses ("check SMTP").
- Provider rate limits (hourly cap) pause the campaign instead of failing good
  emails; the scheduler resumes it after 20 / 40 / 60 minutes.
- `email-campaigns:tick` (every minute): starts due scheduled campaigns, resumes
  rate-limited ones, re-queues recipients stuck "sending" > 15 min, closes
  finished campaigns. Deliberately not recorded in the cron history (per-minute
  rows would flood it).
- Public endpoints: open pixel, signed click redirect (the signature prevents
  an open redirect), signed unsubscribe: GET = confirm page (mail scanners
  prefetch links), POST = unsubscribe (also the RFC 8058 one-click target).
  Mail carries `List-Unsubscribe` + `List-Unsubscribe-Post` headers. Opens are
  approximate (Apple Mail Privacy Protection etc.) and shown as such.

## Owner corrections (follow these)
- **Campaigns are NOT coupled to the Offers module.** I first wired offer merge
  tags into campaigns and gated sending on a live offer; the owner said the two
  are separate, so all of that was removed. Do not couple campaigns to
  offers/plan pricing unless asked.

## Deploy requirements
1. Migrate, then re-seed permissions.
2. **A separate worker for the `campaigns` queue** (1 process). The main worker
   runs `--queue=default` only, so campaign jobs would never run — and a
   separate queue means a large send can never delay video searches.
3. Check the SMTP provider's hourly/daily limit before a large send; lower
   "emails per minute" accordingly. Send the first campaign to a small segment.
4. The local database holds real users: never press "Send now" locally.

## Possible follow-ups (not built)
Bounce/complaint webhooks, A/B subject testing, saved audience segments,
drag-and-drop template builder, push notifications for campaigns.
