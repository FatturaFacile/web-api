<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Modules\Hse\Domain\Enums\Jurisdiction;

final readonly class SaveRequirementMatrixCommand
{
    /**
     * @param  list<array{type: string, validity_months?: int|null}>  $requiredTrainingTypes
     * @param  list<string>  $requiredMedicalExamCodes
     * @param  list<string>  $requiredPpeProductIds
     */
    public function __construct(
        public string $tenantId,
        public string $name,
        public Jurisdiction $jurisdiction,
        public ?string $jobPositionId,
        public ?string $workCenterId,
        public ?string $constructionSiteId,
        public ?string $suvaHazardClass,
        public ?string $dvrRiskProfileCode,
        public array $requiredTrainingTypes,
        public array $requiredMedicalExamCodes,
        public array $requiredPpeProductIds,
        public bool $isActive = true,
        public ?string $matrixId = null,
        public ?int $expectedVersion = null,
    ) {}
}
