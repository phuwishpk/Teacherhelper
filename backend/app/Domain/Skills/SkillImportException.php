<?php

namespace App\Domain\Skills;

use RuntimeException;

/**
 * The CSV was rejected as a whole; `errors` lists every problem found
 * (line numbers are 1-based, line 1 = header).
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
