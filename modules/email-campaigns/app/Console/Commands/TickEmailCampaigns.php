<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Services\EmailCampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs every minute: starts campaigns whose scheduled time has come, rescues
 * recipients left "sending" by a crashed worker, and closes campaigns that
 * have nothing left to send. Deliberately not wrapped in TracksCronRun: a
 * per-minute run would flood the cron history with empty rows.
 */
class TickEmailCampaigns extends Command
{
    protected $signature   = 'email-campaigns:tick';
    protected $description = 'Start due scheduled email campaigns and recover stuck sends';

    public function handle(EmailCampaignService $service): int
    {
        EmailCampaign::where('status', EmailCampaign::SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->each(function (EmailCampaign $c) use ($service) {
                Log::info('Email campaign: starting scheduled campaign', ['id' => $c->id]);
                $service->start($c);
                $this->info("Started campaign #{$c->id}");
            });

        $resumed = $service->resumeDue();
        if ($resumed > 0) {
            Log::info('Email campaign: resumed after provider rate-limit pause', ['count' => $resumed]);
            $this->info("Resumed {$resumed} campaign(s) after a rate-limit pause");
        }

        $rescued = $service->recoverStuck();
        if ($rescued > 0) {
            Log::warning('Email campaign: re-queued stuck recipients', ['count' => $rescued]);
            $this->info("Re-queued {$rescued} stuck recipient(s)");
        }

        EmailCampaign::where('status', EmailCampaign::SENDING)->each(fn (EmailCampaign $c) => $service->maybeComplete($c));

        return self::SUCCESS;
    }
}
