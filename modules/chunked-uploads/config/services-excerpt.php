<?php

// Excerpt from config/services.php

return [
    'chunked_upload' => [
        // Files larger than this (MB) are uploaded in pieces by the browser.
        // Kept under Cloudflare's 100 MB limit with room for form overhead.
        'threshold_mb' => (int) env('CHUNKED_UPLOAD_THRESHOLD_MB', 90),
        // Size of one piece, in bytes. 10 MB uploads in seconds on a normal
        // connection, far inside Cloudflare's 100 s window.
        'chunk_bytes'  => (int) env('CHUNKED_UPLOAD_CHUNK_BYTES', 10 * 1024 * 1024),
        // Unfinished uploads older than this many hours are deleted.
        'keep_hours'   => (int) env('CHUNKED_UPLOAD_KEEP_HOURS', 6),
    ],
];
