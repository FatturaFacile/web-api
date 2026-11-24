<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

use DateTimeImmutable;

/**
 * An absence overlapping the month.
 */
final readonly class AbsenceData
{
    public function __construct(
        public string $absenceType,
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
        public string $percent = '100',
    ) {}
}
