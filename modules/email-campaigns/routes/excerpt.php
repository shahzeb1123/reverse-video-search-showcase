<?php

// Excerpt: how the module is wired into the application's routes.

use App\Console\Commands\TickEmailCampaigns;
use App\Http\Controllers\Home\EmailCampaignTrackingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

// ── routes/web: open pixel, tracked links, unsubscribe (token / signed URL, no login) ──
Route::get('/e/o/{token}', [EmailCampaignTrackingController::class, 'open'])
    ->where('token', '[A-Za-z0-9]{20,40}')->name('email.track.open');
Route::get('/e/c/{token}', [EmailCampaignTrackingController::class, 'click'])
    ->where('token', '[A-Za-z0-9]{20,40}')->name('email.track.click');
// GET = confirm page, POST = do it (also the RFC 8058 one-click target, which carries no CSRF token).
Route::match(['get', 'post'], '/email/unsubscribe/{token}', [EmailCampaignTrackingController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9]{20,40}')
    ->name('email.unsubscribe')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

// ── routes/console: start due scheduled sends, resume rate-limited ones, re-queue stuck recipients ──
Schedule::command(TickEmailCampaigns::class)->everyMinute()->withoutOverlapping();

// The admin panel (compose, audience builder, preview, test send, schedule,
// pause / resume / cancel, retry failed, CSV export, do-not-email list) is a
// permission-guarded controller (Spatie Permission: email-campaigns.view|edit|send|delete)
// that calls EmailCampaignService for every lifecycle change. Not included here.
