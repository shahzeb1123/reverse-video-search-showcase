<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient as Recipient;
use App\Models\EmailSuppression;
use App\Models\NewsletterSubscriber;
use App\Services\EmailCampaignRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public endpoints hit from inside campaign emails: open pixel, tracked
 * links and unsubscribe. None need a login; the recipient token (and, for
 * links and unsubscribe, a URL signature) is the credential.
 */
class EmailCampaignTrackingController extends Controller
{
    /** 1x1 transparent GIF. Opens are approximate: mail clients that pre-load images inflate them. */
    public function open(string $token): Response
    {
        $r = Recipient::where('token', $token)->first();

        if ($r) {
            $first = Recipient::whereKey($r->id)->whereNull('opened_at')->update(['opened_at' => now(), 'open_count' => 1]);
            if ($first) {
                EmailCampaign::whereKey($r->campaign_id)->increment('opened_count');
            } else {
                Recipient::whereKey($r->id)->increment('open_count');
            }
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    public function click(Request $request, string $token)
    {
        abort_unless($request->hasValidSignature(), 403);

        $url = EmailCampaignRenderer::decodeUrl((string) $request->query('u'));
        abort_if($url === null, 404);

        $r = Recipient::where('token', $token)->first();
        if ($r) {
            $first = Recipient::whereKey($r->id)->whereNull('clicked_at')->update(['clicked_at' => now(), 'click_count' => 1]);
            if ($first) {
                EmailCampaign::whereKey($r->campaign_id)->increment('clicked_count');
            } else {
                Recipient::whereKey($r->id)->increment('click_count');
            }
            // A click proves a human read it, even if the image pixel was blocked.
            if (! $r->opened_at) {
                Recipient::whereKey($r->id)->whereNull('opened_at')->update(['opened_at' => now(), 'open_count' => 1]);
                EmailCampaign::whereKey($r->campaign_id)->increment('opened_count');
            }
        }

        return redirect()->away($url);
    }

    /**
     * GET shows a confirm button (mail scanners pre-fetch links, so a bare GET must not
     * unsubscribe anyone). POST does it, and is also what Gmail/Yahoo's one-click button sends.
     */
    public function unsubscribe(Request $request, string $token)
    {
        abort_unless($request->hasValidSignature(), 403, 'Invalid unsubscribe link.');

        $r = Recipient::where('token', $token)->firstOrFail();

        if ($request->isMethod('post')) {
            $this->suppress($r);
            return view('email-campaigns.unsubscribed', ['email' => $r->email]);
        }

        return view('email-campaigns.unsubscribe', ['email' => $r->email, 'already' => EmailSuppression::isSuppressed($r->email)]);
    }

    private function suppress(Recipient $r): void
    {
        EmailSuppression::add($r->email, 'unsubscribed', $r->campaign_id);

        if (! $r->unsubscribed_at) {
            Recipient::whereKey($r->id)->update(['unsubscribed_at' => now()]);
            EmailCampaign::whereKey($r->campaign_id)->increment('unsubscribed_count');
        }

        NewsletterSubscriber::where('email', $r->email)->where('status', 'active')->update(['status' => 'unsubscribed']);
    }
}
