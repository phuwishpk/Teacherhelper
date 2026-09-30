<?php

namespace App\Domain\Skills;

use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One run of SkillCsvImporter (DESIGN §20.2):
 *
 * 1. the header row picks the columns and the format;
 * 2. the (school_id, code) → row map of the scope is loaded once;
 * 3. the file is read record by record; every CHUNK valid rows are split
 *    into inserts (one multi-row INSERT) and updates (one upsert on id),
 *    each chunk in its own transaction;
 * 4. after the last row, parent_code is resolved against the whole file and
 *    the scope (a school's row may hang under a curriculum row), cycles are
 *    refused, and parent_id is written grouped by parent.
 *
 * Codes are compared as exact PHP strings, never through the database
 * collation, so two codes that differ only in case or accents stay apart.
 */
final class SkillImportRun
{
    private const BOM = "\xEF\xBB\xBF";

    private const REQUIRED = ['subject_code', 'code', 'name'];

    private const KNOWN = ['subject_code', 'level', 'code', 'parent_code', 'grade_level', 'name'];

    private SkillImportResult $result;

    private string $source;

    private int $line = 0;

    /** First line of the record record() returned last. */
    private int $recordLine = 0;

    /** @var array<string, int> column => index */
    private array $columns = [];

    private int $width = 0;

    private bool $legacy = false;

    /** @var array<string, array{id: int, subject_id: int, parent_id: int|null, name: string, grade_level: int|null, level: string, source: string}> */
    private array $existing = [];

    /** @var array<string, int> */
    private array $subjectIds = [];

    /** @var array<string, int> code => line, for duplicates within the file */
    private array $seen = [];

    /** @var array<string, array{line: int, parent_code: string}> rows written, by code */
    private array $written = [];

    /** @var array<string, string> code => created|updated|unchanged */
    private array $status = [];

    /** @var list<array{line: int, subject_code: string, code: string, parent_code: string, grade_level: int|null, name: string, level: string}> */
    private array $chunk = [];

    /** @var array<string, int|null> curriculum parents of a school import, by code */
    private array $curriculumIds = [];

    public function __construct(private readonly ?int $schoolId)
    {
        $this->result = new SkillImportResult;
        $this->source = $schoolId === null ? Skill::SOURCE_CURRICULUM : Skill::SOURCE_SCHOOL_ADMIN;
    }

    /**
     * @param  resource  $handle
     *
     * @throws SkillImportException
     */
    public function run($handle): SkillImportResult
    {
        $header = $this->record($handle);
        if ($header === null || trim($header) === '') {
            throw new SkillImportException(['ไฟล์ว่าง']);
        }
        if (str_starts_with($header, self::BOM)) {
            $header = substr($header, 3);
            $this->result->warnings[] = 'ไฟล์มี BOM (ตัดออกให้แล้ว) รูปแบบที่กำหนดคือ UTF-8 ไม่มี BOM';
        }
        $this->header($header);
        $this->loadExisting();

        $rows = 0;
        while (($text = $this->record($handle)) !== null) {
            if (trim($text) === '') {
                continue;
            }
            $rows++;
            $row = $this->row($this->recordLine, $text);
            if ($row !== null) {
                $this->chunk[] = $row;
                if (count($this->chunk) >= SkillCsvImporter::CHUNK) {
                    $this->flush();
                }
            }
        }
        if ($rows === 0) {
            throw new SkillImportException(['ไฟล์ไม่มีข้อมูล (มีแต่แถวหัว)']);
        }
        $this->flush();
        $this->linkParents();

        foreach ($this->status as $status) {
            $this->result->{$status}++;
        }

        return $this->result;
    }

    /**
     * The next CSV record: one line, or several while a quoted field is open.
     *
     * @param  resource  $handle
     */
    private function record($handle): ?string
    {
        $text = fgets($handle);
        if ($text === false) {
            return null;
        }
        $this->line++;
        $this->recordLine = $this->line;
        while (substr_count($text, '"') % 2 === 1) {
            $next = fgets($handle);
            if ($next === false) {
                break;
            }
            $this->line++;
            $text .= $next;
        }

        return rtrim($text, "\r\n");
    }

    /**
     * @throws SkillImportException
     */
    private function header(string $text): void
    {
        $names = array_map(fn ($c) => strtolower(trim((string) $c)), str_getcsv($text, ',', '"', ''));
        $errors = [];
        if (in_array('code', $names, true) && in_array('skill_code', $names, true)) {
            $errors[] = 'บรรทัด 1: มีทั้งคอลัมน์ code และ skill_code ใช้อย่างใดอย่างหนึ่ง';
        }

        $columns = [];
        foreach ($names as $i => $name) {
            $name = $name === 'skill_code' ? 'code' : $name;
            if ($name === '') {
                continue;
            }
            if (! in_array($name, self::KNOWN, true)) {
                $this->result->warnings[] = "ไม่รู้จักคอลัมน์ \"{$name}\" (ข้ามไป)";

                continue;
            }
            if (isset($columns[$name])) {
                if ($errors === []) {
                    $errors[] = "บรรทัด 1: คอลัมน์ {$name} ซ้ำ";
                }

                continue;
            }
            $columns[$name] = $i;
        }
        foreach (self::REQUIRED as $required) {
            if (! isset($columns[$required])) {
                $errors[] = "บรรทัด 1: ไม่มีคอลัมน์ {$required} (หัวตารางต้องเป็น ".implode(',', SkillCsvImporter::HEADER).')';
            }
        }
        if ($errors !== []) {
            throw new SkillImportException(array_values(array_unique($errors)));
        }

        $this->columns = $columns;
        $this->width = count($names);
        $this->legacy = ! isset($columns['level']);
    }

    /**
     * Parses and checks one row; problems are reported and the row skipped.
     *
     * @return array{line: int, subject_code: string, code: string, parent_code: string, grade_level: int|null, name: string, level: string}|null
     */
    private function row(int $line, string $text): ?array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $this->result->error($line, 'ไม่ใช่ข้อความ UTF-8');

            return null;
        }
        $cells = str_getcsv($text, ',', '"', '');
        if (count($cells) !== $this->width) {
            $this->result->error($line, "ต้องมี {$this->width} คอลัมน์ พบ ".count($cells));

            return null;
        }
        $get = fn (string $name) => isset($this->columns[$name]) ? trim((string) $cells[$this->columns[$name]]) : '';
        $subject = $get('subject_code');
        $code = $get('code');
        $parent = $get('parent_code');
        $name = $get('name');
        $gradeText = $get('grade_level');
        $levelText = strtolower($get('level'));

        $problems = [];
        if ($subject === '' || mb_strlen($subject) > 20) {
            $problems[] = 'subject_code ว่างหรือยาวเกิน 20 ตัวอักษร';
        }
        if ($code === '' || mb_strlen($code) > SkillCsvImporter::MAX_CODE_LENGTH) {
            $problems[] = 'code ว่างหรือยาวเกิน '.SkillCsvImporter::MAX_CODE_LENGTH.' ตัวอักษร';
        }
        if (mb_strlen($parent) > SkillCsvImporter::MAX_CODE_LENGTH) {
            $problems[] = 'parent_code ยาวเกิน '.SkillCsvImporter::MAX_CODE_LENGTH.' ตัวอักษร';
        }
        if ($parent !== '' && $parent === $code) {
            $problems[] = 'parent_code ซ้ำกับ code ของตัวเอง';
        }
        if ($name === '') {
            $problems[] = 'name ว่าง';
        }

        $grade = null;
        if ($gradeText !== '') {
            if (! ctype_digit($gradeText) || (int) $gradeText < 1 || (int) $gradeText > 12) {
                $problems[] = 'grade_level ต้องเป็น 1–12 หรือเว้นว่าง';
            } else {
                $grade = (int) $gradeText;
            }
        }

        if ($this->legacy) {
            $level = $parent === '' ? Skill::LEVEL_INDICATOR : Skill::LEVEL_SUB_INDICATOR;
        } else {
            $level = $levelText;
            if (! in_array($level, Skill::LEVELS, true)) {
                $problems[] = 'level ต้องเป็น strand, standard, indicator หรือ sub_indicator';
            } elseif ($level === Skill::LEVEL_STRAND && $parent !== '') {
                $problems[] = 'สาระ (strand) ต้องไม่มี parent_code';
            }
        }

        if ($code !== '') {
            if (isset($this->seen[$code])) {
                $problems[] = "code \"{$code}\" ซ้ำกับบรรทัด {$this->seen[$code]}";
            } else {
                $this->seen[$code] = $line;
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->result->error($line, $problem);
            }

            return null;
        }

        return [
            'line' => $line,
            'subject_code' => $subject,
            'code' => $code,
            'parent_code' => $parent,
            'grade_level' => $grade,
            'name' => $name,
            'level' => $level,
        ];
    }

    /** @return Builder the rows of this import's scope */
    private function scope(): Builder
    {
        $query = DB::table('skills');

        return $this->schoolId === null ? $query->whereNull('school_id') : $query->where('school_id', $this->schoolId);
    }

    private function loadExisting(): void
    {
        foreach ($this->scope()->select(['id', 'code', 'subject_id', 'parent_id', 'name', 'grade_level', 'level', 'source'])->lazyById(2000) as $skill) {
            $this->remember($skill);
        }
    }

    private function remember(object $skill): void
    {
        $this->existing[(string) $skill->code] ??= [
            'id' => (int) $skill->id,
            'subject_id' => (int) $skill->subject_id,
            'parent_id' => $skill->parent_id === null ? null : (int) $skill->parent_id,
            'name' => (string) $skill->name,
            'grade_level' => $skill->grade_level === null ? null : (int) $skill->grade_level,
            'level' => (string) $skill->level,
            'source' => (string) $skill->source,
        ];
    }

    private function subjectId(string $code): int
    {
        if (! isset($this->subjectIds[$code])) {
            $subject = Subject::query()->firstOrCreate(['code' => $code], ['name' => SkillCsvImporter::SUBJECT_NAMES[$code] ?? $code]);
            if ($subject->wasRecentlyCreated) {
                $this->result->subjectsCreated++;
            }
            $this->subjectIds[$code] = $subject->id;
        }

        return $this->subjectIds[$code];
    }

    /** Writes the buffered rows: one INSERT for the new ones, one upsert for the changed ones. */
    private function flush(): void
    {
        if ($this->chunk === []) {
            return;
        }
        $rows = $this->chunk;
        $this->chunk = [];

        DB::transaction(function () use ($rows) {
            $now = now();
            $inserts = [];
            $updates = [];
            foreach ($rows as $row) {
                $code = $row['code'];
                $subjectId = $this->subjectId($row['subject_code']);
                $this->written[$code] = ['line' => $row['line'], 'parent_code' => $row['parent_code']];
                $existing = $this->existing[$code] ?? null;

                if ($existing === null) {
                    $inserts[] = [
                        'subject_id' => $subjectId,
                        'parent_id' => null,
                        'school_id' => $this->schoolId,
                        'code' => $code,
                        'name' => $row['name'],
                        'grade_level' => $row['grade_level'],
                        'level' => $row['level'],
                        'source' => $this->source,
                        'created_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $this->status[$code] = 'created';

                    continue;
                }

                $same = $existing['subject_id'] === $subjectId
                    && $existing['name'] === $row['name']
                    && $existing['grade_level'] === $row['grade_level']
                    && $existing['level'] === $row['level'];
                $this->status[$code] = $same ? 'unchanged' : 'updated';
                if ($same) {
                    continue;
                }
                $updates[] = [
                    'id' => $existing['id'],
                    'subject_id' => $subjectId,
                    'school_id' => $this->schoolId,
                    'code' => $code,
                    'name' => $row['name'],
                    'grade_level' => $row['grade_level'],
                    'level' => $row['level'],
                    'source' => $existing['source'],
                    'updated_at' => $now,
                ];
                $this->existing[$code] = ['subject_id' => $subjectId, 'name' => $row['name'], 'grade_level' => $row['grade_level'], 'level' => $row['level']] + $existing;
            }

            if ($inserts !== []) {
                DB::table('skills')->insert($inserts);
                $codes = array_flip(array_column($inserts, 'code'));
                $found = $this->scope()
                    ->whereIn('code', array_keys($codes))
                    ->get(['id', 'code', 'subject_id', 'parent_id', 'name', 'grade_level', 'level', 'source']);
                foreach ($found as $skill) {
                    if (isset($codes[(string) $skill->code])) {
                        $this->remember($skill);
                    }
                }
            }
            if ($updates !== []) {
                DB::table('skills')->upsert($updates, ['id'], ['subject_id', 'name', 'grade_level', 'level', 'updated_at']);
            }
        });
    }

    /** Resolves every parent_code of the file, then writes parent_id grouped by parent. */
    private function linkParents(): void
    {
        /** @var array<int, int|null> $parentOf id => parent id after this import */
        $parentOf = [];
        foreach ($this->existing as $row) {
            $parentOf[$row['id']] = $row['parent_id'];
        }

        /** @var array<string, int|null> $wanted */
        $wanted = [];
        foreach ($this->written as $code => ['line' => $line, 'parent_code' => $parentCode]) {
            if (! isset($this->existing[$code])) {
                continue;
            }
            if ($parentCode === '') {
                $wanted[$code] = null;

                continue;
            }
            $parentId = $this->existing[$parentCode]['id'] ?? $this->curriculumId($parentCode);
            if ($parentId === null) {
                $this->result->error($line, "ไม่พบ parent_code \"{$parentCode}\" (บันทึกแถวนี้โดยไม่เปลี่ยน parent)");

                continue;
            }
            $wanted[$code] = $parentId;
            $parentOf[$this->existing[$code]['id']] = $parentId;
        }

        // A parent chain that comes back to the row itself would loop forever.
        foreach ($wanted as $code => $parentId) {
            $id = $this->existing[$code]['id'];
            $current = $parentId;
            for ($steps = 0; $current !== null && $steps < 100; $steps++) {
                if ($current === $id) {
                    $this->result->error($this->written[$code]['line'], "parent_code \"{$this->written[$code]['parent_code']}\" ทำให้ลำดับชั้นวนกลับมาที่แถวนี้ (ไม่เปลี่ยน parent)");
                    unset($wanted[$code]);
                    $parentOf[$id] = $this->existing[$code]['parent_id'];

                    break;
                }
                $current = $parentOf[$current] ?? null;
            }
        }

        /** @var array<int, list<int>> $byParent parent id (0 = none) => ids */
        $byParent = [];
        foreach ($wanted as $code => $parentId) {
            $row = $this->existing[$code];
            if ($row['parent_id'] === $parentId) {
                continue;
            }
            $byParent[$parentId ?? 0][] = $row['id'];
            $this->existing[$code]['parent_id'] = $parentId;
            if (($this->status[$code] ?? null) === 'unchanged') {
                $this->status[$code] = 'updated';
            }
        }
        if ($byParent === []) {
            return;
        }

        DB::transaction(function () use ($byParent) {
            $now = now();
            foreach ($byParent as $parentId => $ids) {
                foreach (array_chunk($ids, SkillCsvImporter::CHUNK) as $chunk) {
                    DB::table('skills')->whereIn('id', $chunk)->update(['parent_id' => $parentId === 0 ? null : $parentId, 'updated_at' => $now]);
                }
            }
        });
    }

    /** A school's row may hang under a curriculum row (never the other way round). */
    private function curriculumId(string $code): ?int
    {
        if ($this->schoolId === null) {
            return null;
        }
        if (! array_key_exists($code, $this->curriculumIds)) {
            $id = null;
            foreach (DB::table('skills')->whereNull('school_id')->where('code', $code)->get(['id', 'code']) as $skill) {
                if ((string) $skill->code === $code) {
                    $id = (int) $skill->id;

                    break;
                }
            }
            $this->curriculumIds[$code] = $id;
        }

        return $this->curriculumIds[$code];
    }
}
