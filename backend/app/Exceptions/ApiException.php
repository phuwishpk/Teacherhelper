<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * An API error the mobile app must handle by `code` (DESIGN §9):
 * rendered as {message, errors, code} with the given HTTP status.
 *
 * It is an expected client error (wrong password, wrong school code, pending
 * account), so it is never written to the log: on shared hosting without log
 * rotation that noise would bury real errors.
 */
class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $extra  top-level fields after {message, errors, code}
     *                                       (e.g. existing_student of student_code_taken, DESIGN §24.4)
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $errors = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed> {message, errors, code} and the extra fields
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'errors' => (object) $this->errors,
            'code' => $this->errorCode,
            ...$this->extra,
        ];
    }
}
