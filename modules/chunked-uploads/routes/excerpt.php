<?php

// Excerpt: how the module is wired into the application's routes.

use App\Console\Commands\CleanupChunkedUploads;
use App\Http\Controllers\ChunkedUploadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

// The search route. `rvs.chunks` (AssembleChunkedUpload) must run before the
// quota check and the usage trackers: it turns a chunked upload back into the
// normal `video_file` they all expect.
Route::post('/reverse-video-search', [/* SearchController */])
    ->name('rvs.search')
    ->middleware(['web', 'throttle:60,1', 'email.verified', 'rvs.chunks', 'search.quota']);

// Start an upload, then send it piece by piece. The search itself is the route
// above, with `chunked_upload_id` in place of the file. Signed-in users only.
Route::post('/reverse-video-search/chunks', [ChunkedUploadController::class, 'init'])
    ->name('rvs.chunks.init')
    ->middleware(['web', 'auth', 'email.verified', 'throttle:30,1']);
Route::post('/reverse-video-search/chunks/{upload}/{index}', [ChunkedUploadController::class, 'put'])
    ->name('rvs.chunks.put')
    ->where(['upload' => '[0-9a-fA-F-]{36}', 'index' => '[0-9]{1,5}'])
    ->middleware(['web', 'auth', 'email.verified', 'throttle:600,1']);

// routes/console: delete uploads that were started but never searched (tab
// closed mid-upload). Hourly, because each one can be hundreds of MB.
Schedule::command(CleanupChunkedUploads::class)->hourly()->withoutOverlapping();
