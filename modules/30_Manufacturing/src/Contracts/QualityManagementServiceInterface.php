<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;
use Modules\Manufacturing\Models\MfgQualityPlan;

/**
 * Inspection plans, inspections at receipt and operations, non-conformances.
 */
interface QualityManagementServiceInterface
{
    /**
     * Creates an inspection plan with its characteristics.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPlan(string $tenantId, array $data): MfgQualityPlan;

    /**
     * Records an inspection: evaluates the measurements against the tolerances, passes or fails it and raises a
     * non-conformance for a failure.
     */
    public function executeInspection(RecordQualityInspectionDTO $dto): MfgQualityInspection;

    /**
     * Raises a non-conformance by hand.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordNonConformance(string $tenantId, array $data, ?string $reportedBy = null): MfgQualityNonConformance;

    /**
     * Closes a non-conformance with its disposition and corrective action.
     */
    public function resolveNonConformance(MfgQualityNonConformance $nonConformance, string $disposition, ?string $correctiveAction, ?string $resolvedBy): MfgQualityNonConformance;
}
