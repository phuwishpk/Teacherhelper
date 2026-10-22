<?php

namespace App\Domain\Skills;

use RuntimeException;

/**
 * The CSV could not be imported at all (unreadable file, unknown school, a
 * wrong header row, no data rows, another import running): nothing was
 * written. Problems of single rows are reported in SkillImportResult
 * instead. Line numbers are 1-based, line 1 = the header row.
 */
class SkillImportException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('นำเข้าไฟล์ตัวชี้วัดไม่สำเร็จ: '.implode(' | ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : ''));
    }
}
