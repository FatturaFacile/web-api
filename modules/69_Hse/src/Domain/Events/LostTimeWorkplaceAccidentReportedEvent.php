<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Events;

/**
 * A reportable accident was logged: the statutory claim draft (SUVA Sunet or INAIL) is created from it.
 */
final readonly class LostTimeWorkplaceAccidentReportedEvent
{
    public function __construct(
        public string $tenantId,
        public string $incidentReportId,
        public string $protocolNumber,
        public string $jurisdiction,
        public int $prognosisDays,
    ) {}
}
