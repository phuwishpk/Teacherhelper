<?php

namespace Tests\Feature\Console;

use App\Domain\Scans\ScanRetention;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * DESIGN §7.3 via `eduvision:purge-images`: page images go once the
 * submission is published, crops after schools.crop_retention_until, and a
 * rescan the teacher never confirms expires with its page image.
 */
class ScanRetentionTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
    }

    public function test_page_images_are_deleted_after_the_submission_is_published(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        $rescan = $this->postScan($this->metaFor(1))->assertStatus(201); // supersedes $first
        $page2 = $this->postScan($this->metaFor(2))->assertStatus(201);
        $other = $this->enrollStudent($this->classroom, 20)['student'];
        $unpublished = $this->postScan($this->metaFor(1, qr: $this->qr(1, studentId: $other->id)))->assertStatus(201);
        $disk = Storage::disk('local');
        $paths = Scan::query()->pluck('page_image_path', 'id');

        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);
        $pending = $this->postScan($this->metaFor(2))->assertStatus(202);

        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Page images deleted (published): 3')
            ->assertSuccessful();

        foreach ([$first, $rescan, $page2] as $res) {
            $this->assertNull(Scan::query()->findOrFail($res->json('scan_id'))->page_image_path);
            $this->assertFalse($disk->exists($paths[$res->json('scan_id')]));
        }
        // Not published yet, and a rescan waiting for the teacher, keep their page.
        $this->assertTrue($disk->exists($paths[$unpublished->json('scan_id')]));
        $this->assertNotNull(Scan::query()->findOrFail($pending->json('scan_id'))->page_image_path);
        // Crops stay (they follow crop_retention_until).
        $this->assertSame(6, Response::query()->whereNotNull('crop_path')->count());

        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Page images deleted (published): 0')->assertSuccessful();
    }

    public function test_crops_are_deleted_after_the_school_retention_date(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 20));
        $old = $this->postScan($this->metaFor(2))->assertStatus(201);
        $this->travelTo(now()->setDate(2027, 5, 20));
        $new = $this->postScan($this->metaFor(1))->assertStatus(201);
        $this->assignment->school->update(['crop_retention_until' => '2027-03-31']);
        $disk = Storage::disk('local');
        $oldPaths = Response::query()->where('scan_id', $old->json('scan_id'))->get(['crop_path', 'final_crop_path'])
            ->flatMap(fn (Response $r) => array_filter([$r->crop_path, $r->final_crop_path]))->values()->all();
        $this->assertCount(3, $oldPaths);

        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Crop images deleted (past crop_retention_until): 3')
            ->assertSuccessful();

        foreach ($oldPaths as $path) {
            $this->assertFalse($disk->exists($path));
        }
        $this->assertSame(0, Response::query()->where('scan_id', $old->json('scan_id'))->whereNotNull('crop_path')->count());
        // Scores and readings stay.
        $this->assertSame('5', Response::query()->where('question_id', $this->work->id)->value('cnn_text'));
        // Crops scanned after the retention date stay until the next date is set.
        $this->assertSame(2, Response::query()->where('scan_id', $new->json('scan_id'))->whereNotNull('crop_path')->count());
    }

    public function test_crops_are_kept_before_the_retention_date_passes(): void
    {
        $this->postScan($this->metaFor(1))->assertStatus(201);
        $this->assignment->school->update(['crop_retention_until' => now()->toDateString()]);

        $this->artisan('eduvision:purge-images')->assertSuccessful();

        $this->assertSame(2, Response::query()->whereNotNull('crop_path')->count());
    }

    public function test_leftover_stashes_of_decided_rescans_are_swept_after_a_day(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);
        $waiting = $this->postScan($this->metaFor(1))->assertStatus(202)->json('scan_id');
        $directory = "scans/{$this->assignment->school_id}/{$this->assignment->id}/pending";
        $disk = Storage::disk('local');
        // A stash left behind by a crash after the scan was superseded.
        $disk->put("{$directory}/{$first->json('scan_id')}/regions.json", '{}');

        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Leftover rescan files swept: 0')->assertSuccessful();

        $this->travel(25)->hours();
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Leftover rescan files swept: 1')->assertSuccessful();

        $this->assertFalse($disk->exists("{$directory}/{$first->json('scan_id')}/regions.json"));
        $this->assertTrue($disk->exists("{$directory}/{$waiting}/regions.json"));
    }

    public function test_an_unconfirmed_rescan_expires_with_its_page_image(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);
        $pendingId = $this->postScan($this->metaFor(1))->assertStatus(202)->json('scan_id');
        $pagePath = Scan::query()->findOrFail($pendingId)->page_image_path;
        $stash = "scans/{$this->assignment->school_id}/{$this->assignment->id}/pending/{$pendingId}";
        $disk = Storage::disk('local');
        $published = Response::query()->orderBy('id')->get(['id', 'scan_id', 'crop_path'])->toArray();

        $this->travel(ScanRetention::PENDING_RESCAN_DAYS - 1)->days();
        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Unconfirmed rescans expired (page image and crops deleted): 0')
            ->assertSuccessful();
        $this->assertTrue($disk->exists($pagePath));
        $this->assertTrue($disk->exists("{$stash}/regions.json"));

        $this->travel(2)->days();
        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Unconfirmed rescans expired (page image and crops deleted): 1')
            ->assertSuccessful();

        $scan = Scan::query()->findOrFail($pendingId);
        $this->assertNull($scan->page_image_path);
        $this->assertSame('superseded', $scan->state);
        $this->assertFalse($disk->exists($pagePath));
        $this->assertSame([], $disk->allFiles($stash));
        // The published answers are untouched.
        $this->assertSame($published, Response::query()->orderBy('id')->get(['id', 'scan_id', 'crop_path'])->toArray());
        $this->assertSame('active', Scan::query()->findOrFail($first->json('scan_id'))->state);

        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$pendingId}/confirm-replace")
            ->assertStatus(409)
            ->assertJsonPath('code', 'scan_files_missing');

        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Unconfirmed rescans expired (page image and crops deleted): 0')
            ->assertSuccessful();
    }

    public function test_an_unconfirmed_rescan_expires_at_the_crop_retention_date(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 25));
        $first = $this->postScan($this->metaFor(2))->assertStatus(201);
        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);
        $pendingId = $this->postScan($this->metaFor(2))->assertStatus(202)->json('scan_id');
        $pagePath = Scan::query()->findOrFail($pendingId)->page_image_path;
        $this->assignment->school->update(['crop_retention_until' => '2027-03-31']);
        $this->travelTo(now()->setDate(2027, 4, 2)); // 8 days later, before PENDING_RESCAN_DAYS

        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Crop images deleted (past crop_retention_until): 3')
            ->expectsOutputToContain('Unconfirmed rescans expired (page image and crops deleted): 1')
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($pagePath));
        $this->assertSame([], Storage::disk('local')->allFiles("scans/{$this->assignment->school_id}/{$this->assignment->id}/pending"));
        $this->assertNull(Scan::query()->findOrFail($pendingId)->page_image_path);
        $this->assertSame('superseded', Scan::query()->findOrFail($pendingId)->state);
    }

    public function test_a_confirmed_rescan_is_not_expired(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);
        $pendingId = $this->postScan($this->metaFor(1))->assertStatus(202)->json('scan_id');
        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$pendingId}/confirm-replace")->assertOk();

        $this->travel(ScanRetention::PENDING_RESCAN_DAYS + 1)->days();
        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Unconfirmed rescans expired (page image and crops deleted): 0')
            ->assertSuccessful();

        $scan = Scan::query()->findOrFail($pendingId);
        $this->assertSame('active', $scan->state);
        // Back to grading after the confirmation, so its page image stays for review.
        $this->assertTrue(Storage::disk('local')->exists($scan->page_image_path));
    }

    public function test_leftover_crop_backups_of_an_interrupted_rescan_are_swept_after_a_day(): void
    {
        $directory = "crops/{$this->assignment->school_id}/{$this->assignment->id}/replaced/99";
        $disk = Storage::disk('local');
        $disk->put("{$directory}/5.webp", 'old crop');

        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Leftover rescan files swept: 0')->assertSuccessful();
        $this->assertTrue($disk->exists("{$directory}/5.webp"));

        $this->travel(25)->hours();
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Leftover rescan files swept: 1')->assertSuccessful();
        $this->assertFalse($disk->exists("{$directory}/5.webp"));
    }
}
