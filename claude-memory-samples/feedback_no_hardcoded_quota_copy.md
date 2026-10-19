---
name: feedback-no-hardcoded-quota-copy
description: "Never hardcode quota/price/limit numbers in app or site copy — they live in the settings table and drift; fetch them, even on pre-login screens"
metadata:
  node_type: memory
  type: feedback
---

Any number the product **promises** the user — free searches per day, credit
costs, plan quotas, prices — must be read from its real source (the settings
table via `QuotaService`, or the `plans` table), never typed into a Blade string.

**Why:** the mobile app's Onboarding and Create Account screens hardcoded a
"free searches every day" figure that was higher than what production actually
granted. So the app advertised one number and the backend enforced another —
the product breaks its own promise on the user's first search, and nobody
notices because the copy and the enforcement live in different files. The same
root cause produced a "0 credits" bug the same day (the profile payload returned
a raw column instead of the enforced quota).

Local dev and production disagreed on this value, which is exactly why
eyeballing it locally proves nothing.

**How to apply:**
- Screens shown AFTER login: take the number from the `/me` payload
  (`credits`, `credits_limit`, `credits_period`).
- Screens shown BEFORE login (onboarding, signup, marketing): there is no token,
  so use the public `GET /api/mobile/v1/config` endpoint, which returns the
  public limits from `QuotaService`. Fetch in `mount()` and never let a
  marketing number block the screen from rendering.
- **Do NOT use a hardcoded fallback number when the fetch fails** — that just
  reintroduces this exact bug on any network hiccup. Type the property `?int`,
  start it NULL, and write the copy so it degrades to no number at all
  ("Free searches every day"). Promise nothing rather than a figure you could
  not verify.
- Only expose figures an anonymous visitor could already see on the website.
  A public config endpoint is not a place to leak anything token-worthy.
- Settings are cached per key — after changing a value you must forget the
  cache key or reads stay stale.

Verified the enforced path really is settings-driven by changing the daily
limit in the settings table and watching the app's Home screen update to match.
