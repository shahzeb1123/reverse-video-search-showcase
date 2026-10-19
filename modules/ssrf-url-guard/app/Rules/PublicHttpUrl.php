<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A URL the SERVER may safely fetch on a user's behalf.
 *
 * ── Why Laravel's `url` rule is not enough ──
 * `url` is a SHAPE check. `http://169.254.169.254/latest/meta-data/` and
 * `http://127.0.0.1:8003/` both pass it. The search pipeline then FETCHES
 * whatever it is given — yt-dlp downloads it, and the frame publisher writes
 * from it — using the server's own network position. That turns URL-mode
 * search into a server-side request forgery primitive: a user can reach cloud
 * instance metadata (where credentials live), the Python service itself, and
 * anything else on the host's network.
 *
 * Confirmed before this rule existed: all eight of those targets were accepted
 * and forwarded to the pipeline.
 *
 * ── What this refuses ──
 *   - any scheme other than http/https (file://, gopher://, data:, …)
 *   - loopback, link-local, private (RFC1918), CGNAT, and reserved ranges,
 *     given either as a literal IP or via a hostname that RESOLVES to one
 *   - hostnames that are internal by convention (localhost, *.internal,
 *     *.local, metadata.google.internal)
 *   - credentials in the URL (user:pass@host), which are only ever there to
 *     confuse a parser
 *
 * ── Residual risk, stated rather than hidden ──
 * DNS rebinding. This resolves the host once at validation; the pipeline
 * resolves again when it fetches, and a hostile resolver can answer
 * differently the second time. Closing that properly means pinning the
 * resolved IP through to the fetch, which belongs in the pipeline, not in a
 * validation rule. This rule removes the trivial attack, not every attack.
 */
class PublicHttpUrl implements ValidationRule
{
    /** Hostnames that are internal by convention, whatever they resolve to. */
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google.internal',
        'metadata',
        'instance-data',
    ];

    private const BLOCKED_SUFFIXES = ['.internal', '.local', '.localdomain', '.localhost'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = is_string($value) ? trim($value) : '';

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            $fail('That does not look like a valid video link.');

            return;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('Only http and https links can be scanned.');

            return;
        }

        // user:pass@host is never needed for a public video and is a classic
        // way to make a parser disagree with a fetcher about the real host.
        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('That link cannot be scanned.');

            return;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            $fail('That link points somewhere private and cannot be scanned.');

            return;
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                $fail('That link points somewhere private and cannot be scanned.');

                return;
            }
        }

        foreach ($this->addressesFor($host) as $ip) {
            if (! $this->isPublic($ip)) {
                $fail('That link points somewhere private and cannot be scanned.');

                return;
            }
        }
    }

    /**
     * Every address the host resolves to.
     *
     * A literal IP resolves to itself. For a name, BOTH A and AAAA records are
     * checked — blocking only IPv4 would leave `http://[::1]/` open, and a
     * dual-stack name could point at a public v4 and a private v6.
     *
     * An unresolvable host yields nothing and is allowed through: the fetch
     * will fail on its own, and making search depend on this process's DNS
     * would turn a resolver hiccup into "that link is private".
     *
     * @return list<string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * PUBLIC means routable on the internet.
     *
     * FILTER_FLAG_NO_PRIV_RANGE covers RFC1918 and fc00::/7;
     * FILTER_FLAG_NO_RES_RANGE covers loopback, link-local (including
     * 169.254.169.254, the metadata address that matters most) and the other
     * reserved blocks. CGNAT (100.64.0.0/10) is not in either, so it is
     * checked by hand.
     */
    private function isPublic(string $ip): bool
    {
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($public === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            // 100.64.0.0/10 — carrier-grade NAT, routable inside an ISP or a
            // container network but not on the internet.
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }
}
