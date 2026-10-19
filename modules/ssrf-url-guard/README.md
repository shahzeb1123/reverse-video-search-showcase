# SSRF URL Guard

## The problem

In URL mode, the server **downloads whatever link the user pastes**. Laravel's
built-in `url` rule only checks the *shape* of a URL, so all of these passed it:

```
http://169.254.169.254/latest/meta-data/   ← cloud instance metadata (credentials live here)
http://127.0.0.1:8003/                     ← the internal Python service
http://192.168.1.1/                        ← anything on the private network
```

That's server-side request forgery (SSRF): a user could use the server's own
network position to reach internal systems. All eight internal targets tested
were accepted before this rule existed.

## What the rule refuses

- Any scheme except http/https (`file://`, `gopher://`, `data:` …)
- Loopback, link-local, private (RFC 1918), CGNAT (100.64.0.0/10) and reserved
  ranges, whether given as a literal IP **or via a hostname that resolves to
  one**. Both IPv4 and IPv6 records are checked, because blocking only IPv4
  would leave `http://[::1]/` open.
- Hostnames that are internal by convention (`localhost`, `*.internal`,
  `*.local`, `metadata.google.internal`)
- Credentials in the URL (`user:pass@host`), a classic way to make the
  validator and the downloader disagree about the real host

## Two-sided tests

The tests matter in both directions. A rule that blocks TikTok isn't a
security control, it's an outage. So every platform the product supports is
asserted to **still pass**:

- 14 internal targets must be refused
- 9 real video URLs (YouTube, TikTok, Instagram, Vimeo, Facebook, X …) must pass
- An unresolvable host is deliberately allowed. The fetch fails on its own, and
  blocking it would turn a DNS hiccup into "that link is private".

## Remaining risk, stated openly

**DNS rebinding.** The rule resolves the host once, and the downloader
resolves it again, so a hostile DNS server could answer differently the second
time. Closing that properly means pinning the resolved IP through to the fetch,
which belongs in the download pipeline, not in a validation rule. The class
comment says so explicitly: this rule removes the trivial attack, not every
attack.

## Files

```
app/Rules/PublicHttpUrl.php
tests/Feature/PublicHttpUrlRuleTest.php
```
