<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;

interface QualityManagementServiceInterface
{
    public function executeInspection(RecordQualityInspectionDTO $dto): MfgQualityInspection;

    public function recordNonConformance(
        string $tenantId,
        ?string $qualityInspectionId,
        ?string $productionOrderId,
        string $severity,
        string $defectType,
        string $description,
        string $disposition = 'investigating'
    ): MfgQualityNonConformance;

    public function resolveNonConformance(string $ncId, string $resolution, string $resolvedBy): MfgQualityNonConformance;
}
