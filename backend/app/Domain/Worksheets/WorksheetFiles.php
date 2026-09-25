<?php

namespace App\Domain\Worksheets;

use App\Models\WorksheetPrint;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Private-disk paths of worksheet PDFs (DESIGN §7.3: no public URL; the
 * controller streams them after the policy check) and their retention.
 */
final class WorksheetFiles
{
    /** DESIGN §7.3: a worksheet PDF is deleted 30 days after the print was created. */
    public const RETENTION_DAYS = 30;

    /** Thai error the app shows for a print whose PDF was purged. */
    public const EXPIRED_MESSAGE = 'ไฟล์ใบงานถูกลบแล้วเพราะเก็บไว้ครบ 30 วัน กรุณาสั่งพิมพ์ใหม่';

    private const ROOT = 'worksheets';

    public static function final(WorksheetPrint $print): string
    {
        return self::ROOT.'/'.$print->assignment_id.'/'.$print->id.'.pdf';
    }

    public static function partsDirectory(WorksheetPrint $print): string
    {
        return self::ROOT.'/'.$print->assignment_id.'/'.$print->id.'-parts';
    }

    public static function part(WorksheetPrint $print, int $batch): string
    {
        return self::partsDirectory($print).'/'.str_pad((string) $batch, 3, '0', STR_PAD_LEFT).'.pdf';
    }

    /** Removes the batch parts of a print (after the merge, or when it failed). */
    public static function discardParts(WorksheetPrint $print): void
    {
        Storage::disk('local')->deleteDirectory(self::partsDirectory($print));
    }

    /**
     * Deletes the PDFs (they carry student names) of every print created more
     * than RETENTION_DAYS ago. Run daily by `eduvision:purge-images` (DESIGN
     * §7.2). The rows stay so an assignment that was printed still cannot be
     * deleted; each expired print becomes `failed` with EXPIRED_MESSAGE and no
     * file_path, which the app shows instead of a download link. A print still
     * queued or rendering after 30 days is a dead chain and is expired too.
     *
     * Files under worksheets/ that no row points at any more (a merge that
     * crashed after writing, parts of a killed chain) are swept by their
     * modification time against the same cutoff. Idempotent: a second run
     * expires nothing.
     *
     * @return int the number of prints that expired in this run
     */
    public static function purgeExpired(?CarbonInterface $now = null): int
    {
        $cutoff = ($now ?? now())->toImmutable()->subDays(self::RETENTION_DAYS);
        $disk = Storage::disk('local');
        $expired = 0;

        WorksheetPrint::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q
                ->whereNotNull('file_path')
                ->orWhereIn('status', [WorksheetPrint::STATUS_QUEUED, WorksheetPrint::STATUS_RENDERING]))
            ->chunkById(100, function (Collection $prints) use ($disk, &$expired) {
                foreach ($prints as $print) {
                    /** @var WorksheetPrint $print */
                    $disk->delete(array_values(array_unique(array_filter([self::final($print), $print->file_path]))));
                    self::discardParts($print);
                    $print->update([
                        'status' => WorksheetPrint::STATUS_FAILED,
                        'file_path' => null,
                        'error' => self::EXPIRED_MESSAGE,
                    ]);
                    $expired++;
                }
            });

        foreach ($disk->allFiles(self::ROOT) as $file) {
            if ($disk->lastModified($file) < $cutoff->getTimestamp()) {
                $disk->delete($file);
            }
        }
        // Empty directories go only once they are old as well, so the sweep
        // never races a render job that has just created its parts directory.
        // Deleting entries touches a directory, so one emptied now goes in a
        // later run. Deepest first: a parts directory before its parent.
        $directories = $disk->allDirectories(self::ROOT);
        usort($directories, fn (string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach ($directories as $directory) {
            if ($disk->allFiles($directory) === [] && $disk->allDirectories($directory) === []
                && $disk->lastModified($directory) < $cutoff->getTimestamp()) {
                $disk->deleteDirectory($directory);
            }
        }

        return $expired;
    }
}
