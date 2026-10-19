<?php

namespace App\Console\Commands;

use App\Services\ChunkedUploadService;
use Illuminate\Console\Command;

/**
 * Delete chunked uploads that were started but never turned into a search —
 * the visitor closed the tab mid-upload, or the search request was refused
 * after the pieces were stitched together. A finished upload is moved out of
 * this folder by the search itself, so anything still here after a few hours
 * is an orphan, and these are large.
 */
class CleanupChunkedUploads extends Command
{
    protected $signature   = 'chunked-uploads:cleanup {--hours= : Age in hours before an unfinished upload is removed}';
    protected $description = 'Delete abandoned chunked video uploads';

    public function handle(): int
    {
        $hours   = max(1, (int) ($this->option('hours') ?: config('services.chunked_upload.keep_hours', 6)));
        $removed = ChunkedUploadService::cleanup($hours);

        $this->info("Removed {$removed} abandoned chunked upload(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
