<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * An absence of the employee: paid absences credit the target, the others reduce it.
 */
final readonly class AbsenceEntry
{
    /**
     * @param  string  $startDate  The first local day, as Y-m-d.
     * @param  string  $endDate  The last local day, as Y-m-d.
     */
    public function __construct(
        public string $absenceType,
        public string $startDate,
        public string $endDate,
        public bool $isPaid,
        public float|int|string $percent = 100,
    ) {}
}
