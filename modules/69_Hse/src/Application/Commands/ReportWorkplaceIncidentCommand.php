<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\IncidentType;
use Modules\Hse\Domain\ValueObjects\InjuryDynamics;

final readonly class ReportWorkplaceIncidentCommand
{
    /**
     * @param  list<string>  $involvedEmployeeIds
     * @param  list<string>  $witnessEmployeeIds
     * @param  array<string, mixed>|null  $rootCauseAnalysis
     * @param  list<array<string, mixed>>|null  $correctiveActions
     */
    public function __construct(
        public string $tenantId,
        public IncidentType $incidentType,
        public CarbonImmutable $incidentDate,
        public ?string $locationOrSiteId,
        public ?string $workCenterId,
        public array $involvedEmployeeIds,
        public array $witnessEmployeeIds,
        public string $description,
        public ?string $immediateActionsTaken,
        public int $prognosisDays,
        public ?InjuryDynamics $injuryDynamics,
        public ?array $rootCauseAnalysis,
        public ?array $correctiveActions,
        public ?string $userId,
        /** @var list<string> */
        public array $photos = [],
    ) {}
}
