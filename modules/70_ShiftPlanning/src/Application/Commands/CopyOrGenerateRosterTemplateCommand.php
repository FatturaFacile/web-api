<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\RotationPattern;

/**
 * Creates a draft roster period either from a rotation pattern (5x2, 6x6) laid over a team and a set of shift templates, or
 * as a copy of a previous period. $openSlotsPerTemplate asks for open shifts: template id => open shifts per working day.
 */
final readonly class CopyOrGenerateRosterTemplateCommand
{
    /**
     * @param  list<string>  $employeeIds  the team the pattern rotates (pattern mode)
     * @param  list<string>  $shiftTemplateIds  the templates the pattern rotates through (pattern mode)
     * @param  array<string, int>  $openSlotsPerTemplate
     */
    public function __construct(
        public string $tenantId,
        public string $userId,
        public ?string $locationOrDepartmentId,
        public ?string $productionLineId,
        public PeriodType $periodType,
        public CarbonImmutable $startDate,
        public Jurisdiction $jurisdiction,
        public ?RotationPattern $pattern,
        public array $employeeIds = [],
        public array $shiftTemplateIds = [],
        public ?string $sourcePeriodId = null,
        public array $openSlotsPerTemplate = [],
    ) {}
}
