# Chunked Uploads

## The problem

The site sits behind Cloudflare, which rejects any single request larger than
**100 MB**. Users upload videos of a gigabyte or more.

## The solution

The browser (or the Android app) sends the file in 10 MB pieces. When the
search request arrives with a `chunked_upload_id` instead of a file, a
middleware rebuilds the pieces into **one ordinary uploaded file** *before*
quota, usage tracking, validation and the search controller run.

The rest of the pipeline therefore needed **zero changes**. It can't tell the
file arrived in pieces.

```
POST /reverse-video-search/chunks            → { upload_id, chunk_bytes, total_chunks }
POST /reverse-video-search/chunks/{id}/{n}   → one 10 MB piece (repeat; retries are safe)
POST /reverse-video-search  chunked_upload_id=…
        └─ AssembleChunkedUpload middleware → rebuilt file → quota → search
```

## Details that matter

- **Size checked up front.** The plan's limit is checked when the upload
  *starts*, so nobody uploads 2 GB only to be refused at the end.
- **Idempotent pieces.** Re-sending piece *n* after a dropped connection
  simply replaces it.
- **Strict piece sizes.** Every piece must be exactly `chunk_bytes`, except
  the last, which must be the exact remainder. The rebuilt file's size must
  match the declared size.
- **Ownership.** Uploads are stored by user id. Another user's upload id and an
  unknown id return the same error, so ids can't be probed.
- **No path tricks.** Only a real UUID can name an upload folder.
- **Cleanup in two places.** The middleware deletes the folder as soon as the
  search request ends (success *or* refusal), and an hourly command removes
  anything abandoned, for example when a tab was closed mid-upload.
- **Laravel subtlety, documented in the code.** The middleware uses
  `$request->files->has()`, not `$request->hasFile()`, because Laravel caches
  its converted file list on first access and a file added later would be
  invisible.

## Files

```
app/Services/ChunkedUploadService.php          init / putChunk / assemble / discard / cleanup
app/Http/Middleware/AssembleChunkedUpload.php  rebuilds the file before quota + controller
app/Http/Controllers/ChunkedUploadController.php  the two endpoints (excerpt)
app/Console/Commands/CleanupChunkedUploads.php hourly cleanup
config/services-excerpt.php, routes/excerpt.php
tests/Feature/ChunkedUploadTest.php            8 end-to-end tests with a real MP4
```

The tests drive the real routes with a genuine MP4 cut into pieces, and check
the rebuilt file is **byte-for-byte identical** (SHA-256) to the original.
