<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoSearch;
use App\Models\AsyncSearchJob;
use App\Models\User;
use App\Services\ChunkedUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\MakesPremiumUser;
use Tests\TestCase;

/**
 * Large videos reach the server in pieces (Cloudflare rejects a single request
 * over 100 MB). These tests drive the real routes end to end with a genuine
 * MP4 cut into small pieces: start → send pieces → search with the upload id.
 */
class ChunkedUploadTest extends TestCase
{
    use RefreshDatabase;
    use MakesPremiumUser;

    private const CHUNK = 8192;

    protected function setUp(): void
    {
        parent::setUp();

        // Set for EVERY test (not only those that create a user): tearDown
        // uses it to delete only the files this test wrote.
        $this->startedAt = time() - 1;

        config([
            'services.chunked_upload.chunk_bytes' => self::CHUNK,
            // Everything a premium user uploads here counts as "large", so the
            // search request ends in the background queue like a real big file.
            'services.async_video.threshold_mb'   => 0,
        ]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        // Leave no test uploads behind in real storage.
        foreach ([ChunkedUploadService::root(), storage_path('app/private/async_uploads')] as $root) {
            foreach (glob($root . '/*/*') ?: [] as $f) {
                if (str_contains($f, 'test-cleanup-marker') || filemtime($f) >= $this->startedAt) {
                    @unlink($f);
                }
            }
        }

        parent::tearDown();
    }

    private int $startedAt;

    private function premiumUser(): User
    {
        return $this->makeUserPremium(User::factory()->create(['email_verified_at' => now()]));
    }

    private function video(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/tiny.mp4'));
    }

    /** Start an upload and send every piece. Returns the upload id. */
    private function uploadInPieces(User $user, string $bytes, array $skip = []): string
    {
        $init = $this->actingAs($user)->postJson(route('rvs.chunks.init'), [
            'filename' => 'holiday clip.mp4',
            'size'     => strlen($bytes),
        ])->assertOk()->json();

        $this->assertSame(self::CHUNK, $init['chunk_bytes']);
        $this->assertSame((int) ceil(strlen($bytes) / self::CHUNK), $init['total_chunks']);

        foreach (str_split($bytes, self::CHUNK) as $i => $piece) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            $this->actingAs($user)->post(
                route('rvs.chunks.put', ['upload' => $init['upload_id'], 'index' => $i]),
                ['chunk' => UploadedFile::fake()->createWithContent("piece{$i}.bin", $piece)],
                ['Accept' => 'application/json'],
            )->assertOk();
        }

        return $init['upload_id'];
    }

    public function test_a_video_sent_in_pieces_is_rebuilt_and_queued_like_a_normal_upload(): void
    {
        $user  = $this->premiumUser();
        $bytes = $this->video();
        $id    = $this->uploadInPieces($user, $bytes);

        $res = $this->actingAs($user)->postJson(route('rvs.search'), [
            'mode'              => 'upload',
            'chunked_upload_id' => $id,
        ]);

        $res->assertStatus(202)->assertJsonPath('status', 'queued');

        $job = AsyncSearchJob::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('holiday clip.mp4', $job->original_filename);

        // The rebuilt file is byte-for-byte the original video.
        $stored = storage_path('app/private/' . $job->file_path);
        $this->assertFileExists($stored);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $stored));

        Queue::assertPushed(ProcessVideoSearch::class, 1);

        // Nothing is left behind: pieces deleted, file moved, folder removed.
        $this->assertDirectoryDoesNotExist(ChunkedUploadService::root() . "/{$id}");
    }

    public function test_a_missing_piece_stops_the_search(): void
    {
        $user = $this->premiumUser();
        $id   = $this->uploadInPieces($user, $this->video(), skip: [1]);

        $this->actingAs($user)->postJson(route('rvs.search'), [
            'mode' => 'upload', 'chunked_upload_id' => $id,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(0, AsyncSearchJob::count());
    }

    public function test_another_user_cannot_use_or_add_to_someone_elses_upload(): void
    {
        $owner = $this->premiumUser();
        $id    = $this->uploadInPieces($owner, $this->video());
        $other = $this->makeUserPremium(User::factory()->create(['email_verified_at' => now()]));

        $this->actingAs($other)->post(
            route('rvs.chunks.put', ['upload' => $id, 'index' => 0]),
            ['chunk' => UploadedFile::fake()->createWithContent('x.bin', str_repeat('a', self::CHUNK))],
            ['Accept' => 'application/json'],
        )->assertStatus(422);

        $this->actingAs($other)->postJson(route('rvs.search'), [
            'mode' => 'upload', 'chunked_upload_id' => $id,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_a_piece_of_the_wrong_size_or_position_is_refused(): void
    {
        $user = $this->premiumUser();
        $init = $this->actingAs($user)->postJson(route('rvs.chunks.init'), [
            'filename' => 'a.mp4', 'size' => self::CHUNK * 2 + 100,
        ])->assertOk()->json();

        $put = fn (int $index, int $bytes) => $this->actingAs($user)->post(
            route('rvs.chunks.put', ['upload' => $init['upload_id'], 'index' => $index]),
            ['chunk' => UploadedFile::fake()->createWithContent('p.bin', str_repeat('a', $bytes))],
            ['Accept' => 'application/json'],
        );

        $put(0, self::CHUNK - 1)->assertStatus(422);   // too short for a middle piece
        $put(2, self::CHUNK)->assertStatus(422);       // the last piece must be the 100-byte remainder
        $put(3, 100)->assertStatus(422);               // beyond the last piece
        $put(2, 100)->assertOk();                      // the real last piece
    }

    public function test_the_plan_size_limit_is_enforced_before_anything_is_uploaded(): void
    {
        $user = $this->premiumUser();
        $user->activePlan()->update(['video_size_limit_mb' => 150]);

        $this->actingAs($user)->postJson(route('rvs.chunks.init'), [
            'filename' => 'big.mp4', 'size' => 151 * 1024 * 1024,
        ])->assertStatus(422)->assertJsonPath('message', __('search.server.video_max', ['mb' => 150]));

        $this->actingAs($user)->postJson(route('rvs.chunks.init'), [
            'filename' => 'ok.mp4', 'size' => 149 * 1024 * 1024,
        ])->assertOk();

        $this->actingAs($user)->postJson(route('rvs.chunks.init'), [
            'filename' => 'notes.txt', 'size' => 1000,
        ])->assertStatus(422);
    }

    public function test_guests_cannot_start_a_chunked_upload(): void
    {
        $this->postJson(route('rvs.chunks.init'), ['filename' => 'a.mp4', 'size' => 1000])
            ->assertStatus(401);
    }

    public function test_a_file_that_is_not_really_a_video_is_rejected_after_rebuild(): void
    {
        $user = $this->premiumUser();
        $id   = $this->uploadInPieces($user, str_repeat('not a video at all. ', 2000));

        $this->actingAs($user)->postJson(route('rvs.search'), [
            'mode' => 'upload', 'chunked_upload_id' => $id,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_abandoned_uploads_are_cleaned_up_but_fresh_ones_are_kept(): void
    {
        $user  = $this->premiumUser();
        $old   = $this->uploadInPieces($user, $this->video());
        $fresh = $this->uploadInPieces($user, $this->video());

        touch(ChunkedUploadService::root() . "/{$old}/manifest.json", time() - 7 * 3600);

        $this->artisan('chunked-uploads:cleanup', ['--hours' => 6])->assertSuccessful();

        $this->assertDirectoryDoesNotExist(ChunkedUploadService::root() . "/{$old}");
        $this->assertDirectoryExists(ChunkedUploadService::root() . "/{$fresh}");
    }
}
