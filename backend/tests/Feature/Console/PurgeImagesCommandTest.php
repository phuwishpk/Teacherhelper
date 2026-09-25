<?php

namespace Tests\Feature\Console;

use App\Domain\Worksheets\WorksheetFiles;
use App\Models\Assignment;
use App\Models\User;
use App\Models\WorksheetPrint;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §7.2 / §7.3: the daily `eduvision:purge-images` Scheduled Task
 * deletes worksheet PDFs (they carry student names) 30 days after the print
 * was created. The clock is frozen with travelTo().
 */
class PurgeImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($this->teacher);
        $this->assignment = Assignment::factory()->for_classroom($classroom)->create(['status' => 'ready', 'current_layout_version' => 1]);
    }

    /** A finished print created "now" (frozen clock) with its PDF on the private disk. */
    private function readyPrint(): WorksheetPrint
    {
        $print = WorksheetPrint::create([
            'assignment_id' => $this->assignment->id,
            'layout_version' => 1,
            'requested_by' => $this->teacher->id,
            'status' => WorksheetPrint::STATUS_QUEUED,
        ]);
        $path = WorksheetFiles::final($print);
        $this->putFile($path, now()->getTimestamp());
        $print->update(['status' => WorksheetPrint::STATUS_READY, 'file_path' => $path]);

        return $print;
    }

    private function putFile(string $path, int $mtime): void
    {
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        touch(Storage::disk('local')->path($path), $mtime);
    }

    public function test_worksheet_pdfs_are_deleted_30_days_after_the_print_was_created(): void
    {
        $disk = Storage::disk('local');
        $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00:00', 'UTC'));
        $old = $this->readyPrint();
        // A chain that died mid-render: still `rendering`, a part on disk.
        $stuck = WorksheetPrint::create([
            'assignment_id' => $this->assignment->id,
            'layout_version' => 1,
            'requested_by' => $this->teacher->id,
            'status' => WorksheetPrint::STATUS_RENDERING,
        ]);
        $this->putFile(WorksheetFiles::part($stuck, 0), now()->getTimestamp());

        $this->travelTo(CarbonImmutable::parse('2026-06-03 09:00:00', 'UTC'));
        $recent = $this->readyPrint();

        // 30 days and one second after the first two prints.
        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:01', 'UTC'));
        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Worksheet prints expired: 2')
            ->assertSuccessful();

        $old->refresh();
        $this->assertSame(WorksheetPrint::STATUS_FAILED, $old->status);
        $this->assertNull($old->file_path);
        $this->assertSame(WorksheetFiles::EXPIRED_MESSAGE, $old->error);
        $disk->assertMissing(WorksheetFiles::final($old));

        $this->assertSame(WorksheetPrint::STATUS_FAILED, $stuck->refresh()->status);
        $disk->assertMissing(WorksheetFiles::partsDirectory($stuck));

        $this->assertSame(WorksheetPrint::STATUS_READY, $recent->refresh()->status);
        $disk->assertExists(WorksheetFiles::final($recent));

        // The app sees the reason instead of a download link.
        $this->asUser($this->teacher)->getJson("/api/v1/worksheet-prints/{$old->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.download_url', null)
            ->assertJsonPath('data.error', WorksheetFiles::EXPIRED_MESSAGE);
        $this->asUser($this->teacher)->get("/api/v1/worksheet-prints/{$old->id}/file")
            ->assertStatus(409)
            ->assertJsonPath('code', 'print_not_ready');
        $this->asUser($this->teacher)->get("/api/v1/worksheet-prints/{$recent->id}/file")->assertOk();

        // Exactly 30 days after the second print it is kept; one second later it goes.
        $this->travelTo(CarbonImmutable::parse('2026-07-03 09:00:00', 'UTC'));
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Worksheet prints expired: 0');
        $disk->assertExists(WorksheetFiles::final($recent));

        $this->travelTo(CarbonImmutable::parse('2026-07-03 09:00:01', 'UTC'));
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Worksheet prints expired: 1');
        $disk->assertMissing(WorksheetFiles::final($recent));
        $this->assertSame(WorksheetFiles::EXPIRED_MESSAGE, $recent->refresh()->error);

        // Idempotent; the rows stay, so the printed assignment still cannot be deleted.
        $this->assertSame(0, WorksheetFiles::purgeExpired());
        $this->assertSame(3, WorksheetPrint::query()->count());
    }

    public function test_files_no_print_points_at_are_swept_by_age(): void
    {
        $disk = Storage::disk('local');
        // File times are real, so freeze the clock at the real time.
        $this->freezeTime();
        $old = now()->subDays(31)->getTimestamp();

        $this->putFile('worksheets/77/900.pdf', $old);            // merge crashed after writing
        $this->putFile('worksheets/77/901-parts/000.pdf', $old);  // parts of a killed chain
        $this->putFile('worksheets/78/902.pdf', now()->subDays(29)->getTimestamp());
        $disk->makeDirectory('worksheets/79/903-parts');
        touch($disk->path('worksheets/79/903-parts'), $old);
        $disk->makeDirectory('worksheets/80/904-parts');           // a render job that just started

        $this->artisan('eduvision:purge-images')->assertSuccessful();

        $disk->assertMissing('worksheets/77/900.pdf');
        $disk->assertMissing('worksheets/77/901-parts/000.pdf');
        $disk->assertExists('worksheets/78/902.pdf');
        $disk->assertMissing('worksheets/79/903-parts');
        $disk->assertExists('worksheets/80/904-parts');

        // Deleting entries touched the parent directories; they go once they
        // are old as well.
        $this->travel(31)->days();
        $this->artisan('eduvision:purge-images')->assertSuccessful();

        $disk->assertMissing('worksheets/77');
        $disk->assertMissing('worksheets/78/902.pdf');
        $disk->assertMissing('worksheets/79');
        $disk->assertMissing('worksheets/80');
    }

    public function test_it_succeeds_when_nothing_was_ever_printed(): void
    {
        $this->artisan('eduvision:purge-images')
            ->expectsOutputToContain('Worksheet prints expired: 0')
            ->assertSuccessful();
    }
}
