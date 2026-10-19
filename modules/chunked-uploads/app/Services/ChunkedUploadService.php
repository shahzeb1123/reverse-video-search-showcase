<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chunked video uploads — how a file larger than Cloudflare's 100 MB request
 * limit (free plan, the site is proxied) reaches the server at all.
 *
 * The browser sends the file in fixed-size pieces (default 10 MB), then makes
 * the ordinary search request with `chunked_upload_id` instead of the file.
 * AssembleChunkedUpload (first middleware on that route) stitches the pieces
 * back into ONE normal uploaded file, so everything downstream — quota,
 * usage tracking, validation, the magic-byte check, the async queue — sees
 * exactly what a direct upload looks like. Nothing after assembly knows the
 * file came in pieces.
 *
 * Layout: storage/app/private/chunked/{uuid}/manifest.json + {n}.part, and
 * after assembly {uuid}/assembled.upload (moved away by the search service).
 * Abandoned uploads are removed by `chunked-uploads:cleanup` (hourly).
 */
class ChunkedUploadService
{
    public const ALLOWED_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'mpeg', 'mpg'];

    /** Hard ceiling for "unlimited" plans — disk safety, not a product limit. */
    public const UNLIMITED_CAP_MB = 4096;

    public static function root(): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'chunked');
    }

    public static function chunkBytes(): int
    {
        return max(1024, (int) config('services.chunked_upload.chunk_bytes', 10 * 1024 * 1024));
    }

    /**
     * Start an upload. $limitMb is the user's plan limit (null = unlimited).
     *
     * @return array{upload_id:string, chunk_bytes:int, total_chunks:int}
     * @throws ChunkedUploadException on invalid input (message is user-facing)
     */
    public function init(User $user, string $filename, int $sizeBytes, ?int $limitMb): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            throw new ChunkedUploadException(__('search.server.video_mimes'));
        }

        $capMb = $limitMb ?? self::UNLIMITED_CAP_MB;
        if ($sizeBytes <= 0 || $sizeBytes > $capMb * 1024 * 1024) {
            throw new ChunkedUploadException($limitMb
                ? __('search.server.video_max', ['mb' => $limitMb])
                : __('search.server.video_too_large'));
        }

        $chunkBytes = self::chunkBytes();
        $id         = (string) Str::uuid();
        $dir        = $this->dir($id);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create chunk directory: {$dir}");
        }

        $manifest = [
            'user_id'      => $user->id,
            'filename'     => mb_substr(basename($filename), 0, 255),
            'size'         => $sizeBytes,
            'chunk_bytes'  => $chunkBytes,
            'total_chunks' => (int) ceil($sizeBytes / $chunkBytes),
            'created_at'   => now()->toIso8601String(),
        ];
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest));

        return [
            'upload_id'    => $id,
            'chunk_bytes'  => $chunkBytes,
            'total_chunks' => $manifest['total_chunks'],
        ];
    }

    /**
     * Store one piece. Idempotent: re-sending the same index replaces it, so a
     * retried piece after a dropped connection is harmless.
     *
     * @return int number of pieces received so far
     */
    public function putChunk(User $user, string $id, int $index, UploadedFile $chunk): int
    {
        $manifest = $this->manifestFor($user, $id);

        if ($index < 0 || $index >= $manifest['total_chunks']) {
            throw new ChunkedUploadException(__('search.server.chunk_invalid'));
        }

        // Every piece is exactly chunk_bytes except the last, which is the rest.
        $expected = $index === $manifest['total_chunks'] - 1
            ? $manifest['size'] - $manifest['chunk_bytes'] * ($manifest['total_chunks'] - 1)
            : $manifest['chunk_bytes'];

        if ($chunk->getSize() !== $expected) {
            throw new ChunkedUploadException(__('search.server.chunk_invalid'));
        }

        $chunk->move($this->dir($id), $index . '.part');

        return count(glob($this->dir($id) . DIRECTORY_SEPARATOR . '*.part') ?: []);
    }

    /**
     * Stitch all pieces into one file and wrap it as an UploadedFile, exactly
     * as if the browser had sent the whole video in one request.
     */
    public function assemble(User $user, string $id): UploadedFile
    {
        $manifest = $this->manifestFor($user, $id);
        $dir      = $this->dir($id);

        for ($i = 0; $i < $manifest['total_chunks']; $i++) {
            if (! is_file($dir . DIRECTORY_SEPARATOR . $i . '.part')) {
                throw new ChunkedUploadException(__('search.server.chunk_incomplete'));
            }
        }

        $target = $dir . DIRECTORY_SEPARATOR . 'assembled.upload';
        $out    = fopen($target, 'wb');
        if ($out === false) {
            throw new RuntimeException("Could not open {$target} for writing");
        }

        try {
            for ($i = 0; $i < $manifest['total_chunks']; $i++) {
                $part = $dir . DIRECTORY_SEPARATOR . $i . '.part';
                $in   = fopen($part, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        clearstatcache(true, $target);
        if (filesize($target) !== $manifest['size']) {
            @unlink($target);
            throw new ChunkedUploadException(__('search.server.chunk_incomplete'));
        }

        // Pieces are no longer needed once the whole file exists.
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.part') ?: [] as $part) {
            @unlink($part);
        }

        // test=true: the file did not arrive through PHP's own upload handling
        // (is_uploaded_file() would reject it), but it is a real, complete file.
        return new UploadedFile($target, $manifest['filename'], null, null, true);
    }

    /**
     * Remove one upload's folder and whatever is still in it. Called once the
     * search request is over: on success the video has already been moved out,
     * on a refused search (quota, validation) the rebuilt file is useless —
     * the browser starts a new upload for the next attempt.
     */
    public function discard(string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }

        $dir = $this->dir($id);
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** Remove upload folders older than $hours. Returns how many were removed. */
    public static function cleanup(int $hours): int
    {
        $cutoff  = now()->subHours($hours)->getTimestamp();
        $removed = 0;

        foreach (glob(self::root() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $manifest = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
            $stamp    = is_file($manifest) ? filemtime($manifest) : filemtime($dir);

            if ($stamp !== false && $stamp < $cutoff) {
                foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
                $removed++;
            }
        }

        return $removed;
    }

    private function dir(string $id): string
    {
        return self::root() . DIRECTORY_SEPARATOR . $id;
    }

    private function manifestFor(User $user, string $id): array
    {
        // Only a real UUID can name a folder — no path games.
        if (! Str::isUuid($id)) {
            throw new ChunkedUploadException(__('search.server.chunk_invalid'));
        }

        $path = $this->dir($id) . DIRECTORY_SEPARATOR . 'manifest.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        // Unknown id and someone else's upload look the same from outside.
        if (! is_array($data) || (int) ($data['user_id'] ?? 0) !== (int) $user->id) {
            throw new ChunkedUploadException(__('search.server.chunk_invalid'));
        }

        return $data;
    }
}
