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
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{message: string, errors: object, code: string}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'errors' => (object) $this->errors,
            'code' => $this->errorCode,
        ];
    }
}
