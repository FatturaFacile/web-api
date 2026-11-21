<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Handlers;

use Modules\Hse\Application\Commands\SaveRequirementMatrixCommand;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Exceptions\HseException;
use Modules\Hse\Domain\Exceptions\HseNotFoundException;
use Modules\Hse\Infrastructure\Models\SafetyRoleRequirementMatrix;

/**
 * Creates or updates a requirement rule. An update carries the version the editor loaded: a stale version is refused
 * (optimistic concurrency) instead of overwriting the change of a colleague.
 */
final class SaveRequirementMatrixHandler
{
    public function handle(SaveRequirementMatrixCommand $command): SafetyRoleRequirementMatrix
    {
        foreach ($command->requiredTrainingTypes as $training) {
            if (CertificateType::tryFrom($training['type']) === null) {
                throw new HseException("Unknown training type [{$training['type']}].");
            }
        }

        $attributes = [
            'name' => $command->name,
            'jurisdiction' => $command->jurisdiction,
            'job_position_id' => $command->jobPositionId,
            'work_center_id' => $command->workCenterId,
            'construction_site_id' => $command->constructionSiteId,
            'suva_hazard_class' => $command->suvaHazardClass,
            'dvr_risk_profile_code' => $command->dvrRiskProfileCode,
            'required_training_types_json' => $command->requiredTrainingTypes,
            'required_medical_exam_codes_json' => $command->requiredMedicalExamCodes,
            'required_ppe_product_ids_json' => $command->requiredPpeProductIds,
            'is_active' => $command->isActive,
        ];

        if ($command->matrixId === null) {
            return SafetyRoleRequirementMatrix::create(['tenant_id' => $command->tenantId, ...$attributes])->refresh();
        }

        $matrix = SafetyRoleRequirementMatrix::query()->where('tenant_id', $command->tenantId)->whereKey($command->matrixId)->first()
            ?? throw new HseNotFoundException('Unknown requirement matrix.');

        if ($command->expectedVersion !== null && $command->expectedVersion !== $matrix->version) {
            throw new HseException('The requirement matrix was changed by someone else: reload it.');
        }

        $matrix->fill($attributes);
        $matrix->version = $matrix->version + 1;
        $matrix->save();

        return $matrix;
    }
}
