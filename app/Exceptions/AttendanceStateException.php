<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AttendanceStateException extends RuntimeException
{
    public function __construct(
        public readonly string $title,
        string $detail,
        public readonly int $status = 409
    ) {
        parent::__construct($detail);
    }
}
