<?php

namespace App\Domain\Skills;

use App\Models\School;
use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * Imports the curriculum-indicator CSV of DESIGN §2.3:
 *
 *     subject_code,skill_code,parent_code,grade_level,name
 *
 * UTF-8 without BOM (a BOM is tolerated and reported as a warning). The whole
 * file is validated first and written in one transaction, so a bad row imports
 * nothing. Rows are upserted on (school_id, code): re-importing updates names
 * and grades instead of duplicating (DESIGN §8.2 leaves this to code because
 * MariaDB does not enforce UNIQUE over NULL school_id).
 */
class SkillCsvImporter
{
    public const HEADER = ['subject_code', 'skill_code', 'parent_code', 'grade_level', 'name'];

    public const MAX_CODE_LENGTH = 40;

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
     * @param  int|null  $schoolId  NULL = curriculum skills; a school id = that school's own sub-skills
     *
     * @throws SkillImportException
     */
    public function importFile(string $path, ?int $schoolId = null): SkillImportResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SkillImportException(["อ่านไฟล์ไม่ได้: {$path}"]);
        }

        $csv = file_get_contents($path);
        if ($csv === false) {
            throw new SkillImportException(["อ่านไฟล์ไม่ได้: {$path}"]);
        }

        return $this->importString($csv, $schoolId);
    }

    /**
     * @throws SkillImportException
     */
    public function importString(string $csv, ?int $schoolId = null): SkillImportResult
    {
        $result = new SkillImportResult;

        if ($schoolId !== null && ! School::query()->whereKey($schoolId)->exists()) {
            throw new SkillImportException(["ไม่พบโรงเรียน id {$schoolId}"]);
        }

        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
            $result->warnings[] = 'ไฟล์มี BOM (ตัดออกให้แล้ว) รูปแบบที่กำหนดคือ UTF-8 ไม่มี BOM';
        }

        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw new SkillImportException(['ไฟล์ไม่ใช่ UTF-8']);
        }

        $rows = $this->parse($csv);

        $subjects = [];
        foreach ($rows as $row) {
            $subjects[$row['subject_code']] = true;
        }

        DB::transaction(function () use ($rows, $subjects, $schoolId, $result) {
            $subjectIds = [];
            foreach (array_keys($subjects) as $code) {
                $subject = Subject::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => self::SUBJECT_NAMES[$code] ?? $code],
                );
                if ($subject->wasRecentlyCreated) {
                    $result->subjectsCreated++;
                }
                $subjectIds[$code] = $subject->id;
            }

            /** @var array<string, int> $idsByCode skills created or updated in this run */
            $idsByCode = [];

            foreach ($rows as $row) {
                $parentId = null;
                if ($row['parent_code'] !== '') {
                    $parentId = $idsByCode[$row['parent_code']]
                        ?? $this->findSkillId($row['parent_code'], $schoolId);
                    if ($parentId === null) {
                        throw new SkillImportException(["บรรทัด {$row['line']}: ไม่พบ parent_code \"{$row['parent_code']}\""]);
                    }
                }

                $skill = Skill::query()
                    ->where('code', $row['skill_code'])
                    ->where('school_id', $schoolId)
                    ->first();

                $attributes = [
                    'subject_id' => $subjectIds[$row['subject_code']],
                    'parent_id' => $parentId,
                    'school_id' => $schoolId,
                    'code' => $row['skill_code'],
                    'name' => $row['name'],
                    'grade_level' => $row['grade_level'],
                ];

                if ($skill === null) {
                    $skill = Skill::create($attributes);
                    $result->created++;
                } else {
                    $skill->fill($attributes)->save();
                    $result->updated++;
                }

                $idsByCode[$row['skill_code']] = $skill->id;
            }
        });

        return $result;
    }

    /**
     * Parses and validates every row before anything is written.
     *
     * @return array<int, array{line: int, subject_code: string, skill_code: string, parent_code: string, grade_level: int|null, name: string}>
     *
     * @throws SkillImportException
     */
    private function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $errors = [];
        $rows = [];
        $seen = [];

        $header = array_shift($lines);
        $headerCells = array_map(fn (string $c) => trim($c), str_getcsv((string) $header, ',', '"', ''));
        if ($headerCells !== self::HEADER) {
            throw new SkillImportException(['บรรทัด 1: header ต้องเป็น '.implode(',', self::HEADER)]);
        }

        foreach ($lines as $i => $line) {
            $lineNo = $i + 2;
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== count(self::HEADER)) {
                $errors[] = "บรรทัด {$lineNo}: ต้องมี ".count(self::HEADER).' คอลัมน์ พบ '.count($cells);

                continue;
            }

            [$subjectCode, $skillCode, $parentCode, $gradeLevel, $name] = array_map(fn ($c) => trim((string) $c), $cells);

            if ($subjectCode === '' || mb_strlen($subjectCode) > 20) {
                $errors[] = "บรรทัด {$lineNo}: subject_code ว่างหรือยาวเกิน 20 ตัวอักษร";
            }
            if ($skillCode === '' || mb_strlen($skillCode) > self::MAX_CODE_LENGTH) {
                $errors[] = "บรรทัด {$lineNo}: skill_code ว่างหรือยาวเกิน ".self::MAX_CODE_LENGTH.' ตัวอักษร';
            }
            if ($parentCode !== '' && mb_strlen($parentCode) > self::MAX_CODE_LENGTH) {
                $errors[] = "บรรทัด {$lineNo}: parent_code ยาวเกิน ".self::MAX_CODE_LENGTH.' ตัวอักษร';
            }
            if ($parentCode !== '' && $parentCode === $skillCode) {
                $errors[] = "บรรทัด {$lineNo}: parent_code ซ้ำกับ skill_code ของตัวเอง";
            }
            if ($name === '') {
                $errors[] = "บรรทัด {$lineNo}: name ว่าง";
            }

            $grade = null;
            if ($gradeLevel !== '') {
                if (! ctype_digit($gradeLevel) || (int) $gradeLevel < 1 || (int) $gradeLevel > 12) {
                    $errors[] = "บรรทัด {$lineNo}: grade_level ต้องเป็น 1–12 หรือเว้นว่าง";
                } else {
                    $grade = (int) $gradeLevel;
                }
            }

            if ($skillCode !== '') {
                if (isset($seen[$skillCode])) {
                    $errors[] = "บรรทัด {$lineNo}: skill_code \"{$skillCode}\" ซ้ำกับบรรทัด {$seen[$skillCode]}";
                } else {
                    $seen[$skillCode] = $lineNo;
                }
            }

            $rows[] = [
                'line' => $lineNo,
                'subject_code' => $subjectCode,
                'skill_code' => $skillCode,
                'parent_code' => $parentCode,
                'grade_level' => $grade,
                'name' => $name,
            ];
        }

        if ($errors !== []) {
            throw new SkillImportException($errors);
        }

        if ($rows === []) {
            throw new SkillImportException(['ไฟล์ไม่มีข้อมูล (มีแต่ header)']);
        }

        return $rows;
    }

    /** A parent may be a curriculum skill or one of the same school. */
    private function findSkillId(string $code, ?int $schoolId): ?int
    {
        $id = Skill::query()
            ->where('code', $code)
            ->where(function ($q) use ($schoolId) {
                $q->whereNull('school_id');
                if ($schoolId !== null) {
                    $q->orWhere('school_id', $schoolId);
                }
            })
            ->orderByRaw('school_id is null')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
