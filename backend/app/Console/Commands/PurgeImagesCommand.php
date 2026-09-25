<?php

namespace App\Console\Commands;

use App\Domain\Worksheets\WorksheetFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily retention pass (DESIGN §7.2 Plesk Scheduled Task "ลบภาพตามนโยบาย",
 * 02:00) applying the deletion rules of DESIGN §7.3. It needs no
 * `schedule:run`: Plesk calls it directly.
 *
 * Implemented so far: worksheet PDFs, 30 days after the print was created.
 * Scan images (after publish) and crops (after schools.crop_retention_until)
 * are added here by the steps that store them.
 */
class PurgeImagesCommand extends Command
{
    protected $signature = 'eduvision:purge-images';

    protected $description = 'Delete stored files whose retention period (DESIGN §7.3) has passed';

    public function handle(): int
    {
        $worksheets = WorksheetFiles::purgeExpired();

        Log::info('purge.files', ['worksheet_prints_expired' => $worksheets]);
        $this->info("Worksheet prints expired: {$worksheets}");

        return self::SUCCESS;
    }
}
