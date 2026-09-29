<?php

namespace App\Domain\Skills;

use App\Models\School;
use Illuminate\Support\Facades\Cache;

/**
 * Imports the core-curriculum indicator CSV (DESIGN §20.2, docs/curriculum):
 *
 *     subject_code,level,code,parent_code,grade_level,name
 *
 * and the earlier format without `level` (DESIGN §2.3):
 *
 *     subject_code,skill_code,parent_code,grade_level,name
 *
 * UTF-8 (a BOM is tolerated with a warning). The header row decides the
 * format: the code column may be called `code` or `skill_code` (both is an
 * error on line 1), a `level` column means the new format, and the column
 * order does not matter. In the earlier format a row without parent_code is
 * an `indicator` and a row with one a `sub_indicator`.
 *
 * Built for the whole curriculum (tens of thousands of rows, see
 * SkillImportRun): the file is read as a stream and written 500 rows at a
 * time, parents are linked after the whole file is in (a parent may come
 * after its child), and rows missing from the file are never deleted
 * (mastery already refers to them). Rows are keyed on (school_id, code) in
 * code, because MariaDB does not enforce UNIQUE over the NULL school_id of
 * curriculum rows (§8.2). One import runs at a time
 * (Cache::lock('skills-import')).
 *
 * A row with an error is skipped and reported ({line, message}); the rest
 * is imported. Only a problem with the whole file (unreadable, wrong header,
 * no data rows, unknown school, another import running) throws
 * SkillImportException, and then nothing is written.
 */
class SkillCsvImporter
{
    /** The header of the current format (docs/curriculum/template.csv). */
    public const HEADER = ['subject_code', 'level', 'code', 'parent_code', 'grade_level', 'name'];

    /** The header of the earlier format (DESIGN §2.3), still accepted. */
    public const LEGACY_HEADER = ['subject_code', 'skill_code', 'parent_code', 'grade_level', 'name'];

    public const MAX_CODE_LENGTH = 40;

    /** Rows written per batch (DESIGN §20.2). */
    public const CHUNK = 500;

    public const LOCK = 'skills-import';

    /** A lock left by a crashed import frees itself after this long. */
    private const LOCK_SECONDS = 900;

    /** Thai core-curriculum subject groups; codes not listed are named after the code. */
    public const SUBJECT_NAMES = [
        'ท' => 'ภาษาไทย',
        'ค' => 'คณิตศาสตร์',
        'ว' => 'วิทยาศาสตร์และเทคโนโลยี',
        'ส' => 'สังคมศึกษา ศาสนา และวัฒนธรรม',
        'พ' => 'สุขศึกษาและพลศึกษา',
        'ศ' => 'ศิลปะ',
        'ง' => 'การงานอาชีพ',
        'ต' => 'ภาษาต่างประเทศ',
    ];

    /**
     * @param  int|null  $schoolId  NULL = the curriculum (every school); a school id = that school's own rows
     *
     * @throws SkillImportException
     */
    public function importFile(string $path, ?int $schoolId = null): SkillImportResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SkillImportException(["อ่านไฟล์ไม่ได้: {$path}"]);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new SkillImportException(["อ่านไฟล์ไม่ได้: {$path}"]);
        }

        try {
            return $this->importStream($handle, $schoolId);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws SkillImportException
     */
    public function importString(string $csv, ?int $schoolId = null): SkillImportResult
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new SkillImportException(['อ่านไฟล์ไม่ได้']);
        }
        fwrite($handle, $csv);
        rewind($handle);

        try {
            return $this->importStream($handle, $schoolId);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle  positioned at the header row
     *
     * @throws SkillImportException
     */
    public function importStream($handle, ?int $schoolId = null): SkillImportResult
    {
        if ($schoolId !== null && ! School::query()->whereKey($schoolId)->exists()) {
            throw new SkillImportException(["ไม่พบโรงเรียน id {$schoolId}"]);
        }

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new SkillImportException(['มีการนำเข้าตัวชี้วัดอีกงานกำลังทำอยู่ ลองใหม่เมื่องานนั้นเสร็จ']);
        }

        try {
            return (new SkillImportRun($schoolId))->run($handle);
        } finally {
            $lock->release();
        }
    }
}
