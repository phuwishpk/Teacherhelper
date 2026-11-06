<?php

namespace App\Domain\Gradebook;

use App\Models\GradebookCategory;
use App\Models\User;
use RuntimeException;

/**
 * The CSV export of a classroom's live gradebook (DESIGN §23.8): UTF-8 with
 * a BOM so Excel reads Thai, comma separated, CRLF line ends, RFC 4180
 * quoting by fputcsv. Columns: เลขที่, ชื่อ, one per category
 * "<name> (<weight>)" with the weighted points, then รวม (the rounded
 * total) and เกรด; an incomplete classroom has "รวมระหว่างภาค (ร้อยละ)"
 * and no numeric grade. Text that a spreadsheet would run as a formula
 * gets a leading apostrophe.
 */
final class GradebookCsv
{
    public const BOM = "\xEF\xBB\xBF";

    public static function build(ClassroomGradebook $book): string
    {
        $complete = $book->complete();
        $lines = [];
        $header = ['เลขที่', 'ชื่อ'];
        foreach ($book->categories as $category) {
            /** @var GradebookCategory $category */
            $header[] = self::text($category->name.' ('.GradebookSettings::formatWeight($category->weight).')');
        }
        $header[] = $complete ? 'รวม' : 'รวมระหว่างภาค (ร้อยละ)';
        $header[] = 'เกรด';
        $lines[] = $header;

        foreach ($book->students as $student) {
            /** @var User $student */
            $row = $book->result['rows'][$student->id] ?? null;
            $line = [(string) $student->pivot->student_number, self::text($student->name)];
            foreach ($book->categories as $category) {
                $points = $row['categories'][$category->id]['points'] ?? null;
                $line[] = $points === null ? '' : number_format($points, 2, '.', '');
            }
            if ($complete) {
                $line[] = $row['total_rounded'] === null ? '' : (string) $row['total_rounded'];
                $line[] = GradeCutoffs::label($row['grade'], $row['special']);
            } else {
                $line[] = $row === null || $row['total'] === null ? '' : number_format($row['total'], 2, '.', '');
                $line[] = GradeCutoffs::label(null, $row['special'] ?? null);
            }
            $lines[] = $line;
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('cannot open a temporary stream');
        }
        foreach ($lines as $line) {
            fputcsv($handle, $line, ',', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::BOM.$csv;
    }

    /** A file name without path separators: gradebook-{code}-{classroom}.csv. */
    public static function fileName(string $courseCode, string $classroomName): string
    {
        $clean = fn (string $s) => trim(preg_replace('/[\/\\\\\x00-\x1F"%]+/u', '-', $s) ?? '', '- ');

        return 'gradebook-'.$clean($courseCode).'-'.$clean($classroomName).'.csv';
    }

    /** Text a spreadsheet would treat as a formula gets a leading apostrophe (§23.8). */
    public static function text(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
