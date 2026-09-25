<?php

namespace App\Domain\Scans;

use App\Models\Assignment;
use App\Models\Response;
use App\Models\Scan;
use App\Models\School;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Scan-image retention of DESIGN §7.3, run daily by eduvision:purge-images:
 *
 * - page images: deleted once the submission is published (active and
 *   superseded scans; a pending_confirm rescan keeps its page until the
 *   teacher decides). scans.page_image_path becomes NULL.
 * - crops: deleted after schools.crop_retention_until (the end of the
 *   academic year) for every scan made up to that date, including the stash
 *   of a rescan still waiting for confirmation. crop_path / final_crop_path
 *   become NULL; the scores and readings stay.
 * - stash directories of scans that are no longer pending_confirm (normally
 *   removed right after commit) are swept once a day old, so the sweep never
 *   races an upload whose transaction has not committed yet.
 *
 * Idempotent: a second run deletes nothing.
 */
final class ScanRetention
{
    public const ORPHAN_STASH_HOURS = 24;

    /**
     * @return array{page_images: int, crops: int, pending: int}
     */
    public static function purge(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());

        $crops = 0;
        $pending = 0;
        School::query()
            ->whereNotNull('crop_retention_until')
            ->where('crop_retention_until', '<', $now->toDateString())
            ->each(function (School $school) use (&$crops, &$pending) {
                [$c, $p] = self::purgeSchoolCrops($school);
                $crops += $c;
                $pending += $p;
            });

        return [
            'page_images' => self::purgePublishedPages(),
            'crops' => $crops,
            'pending' => $pending + self::sweepOrphanStashes($now),
        ];
    }

    private static function purgePublishedPages(): int
    {
        $disk = ScanFiles::disk();
        $count = 0;

        Scan::query()
            ->whereNotNull('page_image_path')
            ->whereIn('state', [Scan::STATE_ACTIVE, Scan::STATE_SUPERSEDED])
            ->whereIn('submission_id', Submission::query()->select('id')->where('status', Submission::STATUS_PUBLISHED))
            ->chunkById(200, function (Collection $scans) use ($disk, &$count) {
                foreach ($scans as $scan) {
                    /** @var Scan $scan */
                    $disk->delete($scan->page_image_path);
                    $scan->page_image_path = null;
                    $scan->save();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @return array{0: int, 1: int} crop files deleted, pending stashes deleted
     */
    private static function purgeSchoolCrops(School $school): array
    {
        $disk = ScanFiles::disk();
        $cutoff = CarbonImmutable::parse($school->crop_retention_until->toDateString())->endOfDay();
        $submissions = Submission::query()->select('id')->whereIn(
            'assignment_id',
            Assignment::query()->select('id')->where('school_id', $school->id),
        );
        $scansUpToCutoff = Scan::query()->select('id')->where('created_at', '<=', $cutoff);

        $files = 0;
        Response::query()
            ->where(fn ($q) => $q->whereNotNull('crop_path')->orWhereNotNull('final_crop_path'))
            ->whereIn('submission_id', $submissions)
            ->whereIn('scan_id', $scansUpToCutoff)
            ->chunkById(200, function (Collection $responses) use ($disk, &$files) {
                foreach ($responses as $response) {
                    /** @var Response $response */
                    $paths = array_values(array_filter([$response->crop_path, $response->final_crop_path]));
                    $disk->delete($paths);
                    $files += count($paths);
                    $response->crop_path = null;
                    $response->final_crop_path = null;
                    $response->save();
                }
            });

        $stashes = 0;
        Scan::query()
            ->with('submission.assignment')
            ->where('state', Scan::STATE_PENDING_CONFIRM)
            ->where('created_at', '<=', $cutoff)
            ->whereIn('submission_id', $submissions)
            ->chunkById(200, function (Collection $scans) use ($disk, &$stashes) {
                foreach ($scans as $scan) {
                    /** @var Scan $scan */
                    $assignment = $scan->submission->assignment;
                    $directory = ScanFiles::pendingDirectory($assignment->school_id, $assignment->id, $scan->id);
                    if ($disk->exists(ScanFiles::pendingMeta($directory)) || $disk->allFiles($directory) !== []) {
                        $disk->deleteDirectory($directory);
                        $stashes++;
                    }
                }
            });

        return [$files, $stashes];
    }

    private static function sweepOrphanStashes(CarbonImmutable $now): int
    {
        $disk = ScanFiles::disk();
        $cutoff = $now->subHours(self::ORPHAN_STASH_HOURS)->getTimestamp();
        $candidates = [];
        foreach ($disk->allDirectories('scans') as $directory) {
            if (preg_match('#\Ascans/\d+/\d+/pending/(\d+)\z#', $directory, $m) === 1) {
                $candidates[(int) $m[1]] = $directory;
            }
        }
        if ($candidates === []) {
            return 0;
        }

        $stillPending = Scan::query()
            ->whereIn('id', array_keys($candidates))
            ->where('state', Scan::STATE_PENDING_CONFIRM)
            ->pluck('id')
            ->all();

        $swept = 0;
        foreach ($candidates as $scanId => $directory) {
            if (in_array($scanId, $stillPending, true) || $disk->lastModified($directory) >= $cutoff) {
                continue;
            }
            $disk->deleteDirectory($directory);
            $swept++;
        }

        return $swept;
    }
}
