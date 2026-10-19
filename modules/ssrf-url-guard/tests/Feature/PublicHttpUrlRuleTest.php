<?php

namespace Tests\Feature;

use App\Rules\PublicHttpUrl;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SSRF guard must refuse internal targets WITHOUT refusing real videos.
 *
 * The second half matters as much as the first: a rule that blocks TikTok is
 * not a security control, it is an outage. Every platform the product
 * advertises is asserted here.
 */
class PublicHttpUrlRuleTest extends TestCase
{
    private function fails(string $url): bool
    {
        return Validator::make(
            ['u' => $url],
            ['u' => [new PublicHttpUrl]]
        )->fails();
    }

    /** @return list<array{0:string}> */
    public static function internal(): array
    {
        return array_map(fn ($u) => [$u], [
            'http://169.254.169.254/latest/meta-data/',  // AWS/GCP metadata
            'http://metadata.google.internal/',
            'http://127.0.0.1:8003/analyze-url/',
            'http://localhost/admin',
            'http://192.168.1.1/',
            'http://10.0.0.1/',
            'http://172.16.0.1/',
            'http://[::1]/',
            'http://100.64.0.1/',                        // CGNAT
            'http://something.internal/x',
            'http://box.local/x',
            'file:///etc/passwd',
            'gopher://127.0.0.1:8003/',
            'http://user:pass@127.0.0.1/',               // credentials to confuse a parser
        ]);
    }

    /** @return list<array{0:string}> */
    public static function realVideos(): array
    {
        return array_map(fn ($u) => [$u], [
            'https://www.tiktok.com/@user/video/7401234567890',
            'https://vt.tiktok.com/ZSVQm13xe/',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.instagram.com/reel/Cabc123/',
            'https://vimeo.com/123456789',
            'https://www.facebook.com/watch/?v=123456',
            'https://x.com/user/status/123456',
            'https://twitter.com/user/status/123456',
        ]);
    }

    #[DataProvider('internal')]
    public function test_internal_targets_are_refused(string $url): void
    {
        $this->assertTrue($this->fails($url), "{$url} was ALLOWED — the server would fetch it");
    }

    #[DataProvider('realVideos')]
    public function test_real_video_links_still_pass(string $url): void
    {
        $this->assertFalse($this->fails($url), "{$url} was BLOCKED — this rule would break the product");
    }

    /**
     * An unresolvable host is allowed through deliberately: the fetch fails on
     * its own, and making search depend on the validator's DNS would turn a
     * resolver hiccup into "that link is private".
     */
    public function test_an_unresolvable_host_is_not_treated_as_private(): void
    {
        $this->assertFalse($this->fails('https://this-host-does-not-exist-9f3a2b.example/video'));
    }
}
