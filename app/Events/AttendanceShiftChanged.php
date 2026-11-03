<?php

declare(strict_types=1);

namespace App\Events;

final readonly class AttendanceShiftChanged
{
    /** @param list<string> $workDates */
    public function __construct(
        public string $tenantId,
        public string $userId,
        public array $workDates
    ) {}
}
