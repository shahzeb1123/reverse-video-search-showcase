<?php

namespace App\Http\Middleware;

use App\Services\ChunkedUploadException;
use App\Services\ChunkedUploadService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * FIRST middleware on the search route. If the browser sent a large video in
 * pieces (see ChunkedUploadService) the request carries `chunked_upload_id`
 * instead of `video_file`: stitch the pieces into one file and put it on the
 * request as `video_file`, BEFORE quota, usage tracking and the controller
 * run — so they all handle it exactly like a file uploaded in one go.
 *
 * Signed-in users only: large uploads are a paid-plan feature and the pieces
 * are owned by user id.
 */
class AssembleChunkedUpload
{
    public function __construct(private ChunkedUploadService $chunks) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->input('chunked_upload_id');

        // `$request->files->has()`, NOT `$request->hasFile()`: Laravel caches its
        // converted file list on first use, and a file added to the request
        // after that (below) would be invisible to `$request->file()`.
        if (! $id || $request->input('mode') !== 'upload' || $request->files->has('video_file')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'status'  => 'error',
                'message' => __('search.server.signup_required'),
            ], 401);
        }

        try {
            $file = $this->chunks->assemble($user, (string) $id);
        } catch (ChunkedUploadException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Chunked upload assembly failed', [
                '_channel' => 'upload',
                'user_id'  => $user->id,
                'upload'   => $id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => __('search.server.generic_error'),
            ], 500);
        }

        $request->files->set('video_file', $file);

        Log::info('Chunked upload assembled', [
            '_channel' => 'upload',
            'user_id'  => $user->id,
            'upload'   => $id,
            'size_mb'  => round($file->getSize() / 1048576, 2),
        ]);

        try {
            return $next($request);
        } finally {
            // The search has taken the file (or refused it): nothing in the
            // upload folder is needed any more — don't wait for the hourly cleanup.
            $this->chunks->discard((string) $id);
        }
    }
}
