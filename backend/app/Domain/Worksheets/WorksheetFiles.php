<?php

namespace App\Domain\Worksheets;

use App\Models\WorksheetPrint;
use Illuminate\Support\Facades\Storage;

/**
 * Private-disk paths of worksheet PDFs (DESIGN §7.3: no public URL; the
 * controller streams them after the policy check).
 */
final class WorksheetFiles
{
    public static function final(WorksheetPrint $print): string
    {
        return 'worksheets/'.$print->assignment_id.'/'.$print->id.'.pdf';
    }

    public static function partsDirectory(WorksheetPrint $print): string
    {
        return 'worksheets/'.$print->assignment_id.'/'.$print->id.'-parts';
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
}
