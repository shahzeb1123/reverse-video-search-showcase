<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Services\EmailCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Builds the recipient list for a campaign, then queues the individual sends. */
class PrepareEmailCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 3600;

    public function __construct(public readonly int $campaignId) {}

    public function handle(EmailCampaignService $service): void
    {
        $campaign = EmailCampaign::find($this->campaignId);

        if (! $campaign || $campaign->status !== EmailCampaign::PREPARING) {
            return; // cancelled or already handled
        }

        $service->prepare($campaign);
    }

    public function failed(Throwable $e): void
    {
        EmailCampaign::whereKey($this->campaignId)->where('status', EmailCampaign::PREPARING)->update([
            'status' => EmailCampaign::FAILED, 'completed_at' => now(),
            'status_note' => 'Could not build the recipient list: ' . mb_substr($e->getMessage(), 0, 200),
        ]);
    }
}
