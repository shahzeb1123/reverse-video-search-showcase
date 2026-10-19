<?php

namespace App\Http\Controllers;

use App\Services\ChunkedUploadException;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two upload endpoints. In the product these live on the search tool's
 * controller; they are shown on their own here. The plan's size limit comes
 * from the billing layer, which is not part of this showcase.
 */
class ChunkedUploadController extends Controller
{
    public function init(Request $request, ChunkedUploadService $chunks): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size'     => ['required', 'integer', 'min:1'],
        ]);

        try {
            return response()->json(['status' => 'ok'] + $chunks->init(
                $request->user(),
                $data['filename'],
                (int) $data['size'],
                $this->videoSizeLimitMb($request), // plan limit in MB, null = unlimited
            ));
        } catch (ChunkedUploadException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function put(Request $request, ChunkedUploadService $chunks, string $upload, string $index): JsonResponse
    {
        // `max` measures the file itself, in kilobytes.
        $maxKb = (int) ceil(ChunkedUploadService::chunkBytes() / 1024);

        $request->validate([
            'chunk' => ['required', 'file', "max:{$maxKb}"],
        ]);

        try {
            $received = $chunks->putChunk($request->user(), $upload, (int) $index, $request->file('chunk'));
        } catch (ChunkedUploadException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'ok', 'received' => $received]);
    }

    /**
     * Upload limit in MB for this user; null means unlimited (still capped at
     * ChunkedUploadService::UNLIMITED_CAP_MB for disk safety). In the product
     * the free limit is an admin setting; a fixed fallback is shown here.
     */
    private function videoSizeLimitMb(Request $request): ?int
    {
        $user = $request->user();

        if ($user->isPremium()) {
            return $user->activePlan()?->video_size_limit_mb;
        }

        return 50;
    }
}
