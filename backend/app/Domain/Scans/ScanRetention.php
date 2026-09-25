<?php

namespace App\Domain\Scans;

use App\Models\Assignment;
use App\Models\Response;
use App\Models\Scan;
use App\Models\School;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scan-image retention of DESIGN §7.3, run daily by eduvision:purge-images:
 *
 * - page images: deleted once the submission is published (active and
 *   superseded scans). scans.page_image_path becomes NULL.
 * - crops: deleted after schools.crop_retention_until (the end of the
 *   academic year) for every scan made up to that date. crop_path /
 *   final_crop_path become NULL; the scores and readings stay.
 * - a rescan of a published submission that the teacher has not confirmed
 *   (pending_confirm) keeps its page image and stashed crops so the teacher
 *   can still decide, but only for PENDING_RESCAN_DAYS, and never past the
 *   school's crop_retention_until. Then it expires: the page image and the
 *   stash are deleted, page_image_path becomes NULL and the scan becomes
 *   `superseded` (it can no longer be confirmed). The full-page photo of a
 *   published submission therefore never stays on disk for long.
 * - leftovers of an interrupted request are swept once a day old, so the
 *   sweep never races an upload whose transaction has not committed yet:
 *   stash directories of scans that are no longer pending_confirm and the
 *   crop backups of a rescan (CropSwap).
 *
 * Idempotent: a second run deletes nothing.
 */
final class ScanRetention
{
    public const ORPHAN_STASH_HOURS = 24;

    /** Days a rescan of a published submission waits for the teacher's confirmation. */
    public const PENDING_RESCAN_DAYS = 30;

    /**
     * @return array{page_images: int, crops: int, pending_expired: int, leftovers: int}
     */
    public static function purge(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());

        $crops = 0;
        $expired = 0;
        School::query()
            ->whereNotNull('crop_retention_until')
            ->where('crop_retention_until', '<', $now->toDateString())
            ->each(function (School $school) use (&$crops, &$expired) {
                [$c, $p] = self::purgeSchoolCrops($school);
                $crops += $c;
                $expired += $p;
            });

        $expired += self::expirePendingRescans(
            Scan::query()->where('created_at', '<=', $now->subDays(self::PENDING_RESCAN_DAYS)),
        );

        return [
            'page_images' => self::purgePublishedPages(),
            'crops' => $crops,
            'pending_expired' => $expired,
            'leftovers' => self::sweepOrphanStashes($now) + self::sweepCropBackups($now),
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
     * @return array{0: int, 1: int} crop files deleted, pending rescans expired
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

        $expired = self::expirePendingRescans(
            Scan::query()->where('created_at', '<=', $cutoff)->whereIn('submission_id', $submissions),
        );

        return [$files, $expired];
    }

    /**
     * Expires the pending_confirm scans the query selects.
     *
     * @param  Builder<Scan>  $scans
     */
    private static function expirePendingRescans(Builder $scans): int
    {
        $expired = 0;
        $scans
            ->where('state', Scan::STATE_PENDING_CONFIRM)
            ->pluck('id')
            ->each(function (int $scanId) use (&$expired) {
                if (self::expirePending($scanId)) {
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Under the same locks as confirm-replace (submission, then scan), so a
     * teacher confirming at this moment either wins or gets 409.
     */
    private static function expirePending(int $scanId): bool
    {
        return DB::transaction(function () use ($scanId) {
            $submissionId = Scan::query()->whereKey($scanId)->value('submission_id');
            if ($submissionId === null) {
                return false;
            }
            Submission::query()->lockForUpdate()->find($submissionId);
            $scan = Scan::query()->with('submission.assignment')->lockForUpdate()->find($scanId);
            if ($scan === null || ! $scan->isPendingConfirm()) {
                return false;
            }

            $assignment = $scan->submission->assignment;
            $disk = ScanFiles::disk();
            $disk->deleteDirectory(ScanFiles::pendingDirectory($assignment->school_id, $assignment->id, $scan->id));
            if ($scan->page_image_path !== null) {
                $disk->delete($scan->page_image_path);
            }

            $scan->page_image_path = null;
            $scan->state = Scan::STATE_SUPERSEDED;
            $scan->save();

            return true;
        });
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

    /**
     * Backups of replaced crops (CropSwap) live only while a request runs;
     * one older than a day was left by a process that died mid-request.
     */
    private static function sweepCropBackups(CarbonImmutable $now): int
    {
        $disk = ScanFiles::disk();
        $cutoff = $now->subHours(self::ORPHAN_STASH_HOURS)->getTimestamp();
        $swept = 0;
        foreach ($disk->allDirectories('crops') as $directory) {
            if (preg_match('#\Acrops/\d+/\d+/replaced/\d+\z#', $directory) !== 1 || $disk->lastModified($directory) >= $cutoff) {
                continue;
            }
            $disk->deleteDirectory($directory);
            $swept++;
        }

        return $swept;
    }
}
