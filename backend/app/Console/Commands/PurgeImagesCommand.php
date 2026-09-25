<?php

namespace App\Console\Commands;

use App\Domain\Scans\ScanRetention;
use App\Domain\Worksheets\WorksheetFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily retention pass (DESIGN §7.2 Plesk Scheduled Task "ลบภาพตามนโยบาย",
 * 02:00) applying the deletion rules of DESIGN §7.3. It needs no
 * `schedule:run`: Plesk calls it directly.
 *
 * - worksheet PDFs: 30 days after the print was created (WorksheetFiles);
 * - scanned page images: after the submission is published;
 * - answer crops: after schools.crop_retention_until;
 * - rescans of a published submission never confirmed by the teacher:
 *   after ScanRetention::PENDING_RESCAN_DAYS (ScanRetention).
 */
class PurgeImagesCommand extends Command
{
    protected $signature = 'eduvision:purge-images';

    protected $description = 'Delete stored files whose retention period (DESIGN §7.3) has passed';

    public function handle(): int
    {
        $worksheets = WorksheetFiles::purgeExpired();
        $scans = ScanRetention::purge();

        Log::info('purge.files', ['worksheet_prints_expired' => $worksheets, ...$scans]);
        $this->info("Worksheet prints expired: {$worksheets}");
        $this->info("Page images deleted (published): {$scans['page_images']}");
        $this->info("Crop images deleted (past crop_retention_until): {$scans['crops']}");
        $this->info("Unconfirmed rescans expired (page image and crops deleted): {$scans['pending_expired']}");
        $this->info("Leftover rescan files swept: {$scans['leftovers']}");

        return self::SUCCESS;
    }
}
