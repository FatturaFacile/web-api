<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Hse\Domain\Enums\CertificateType;
use Modules\Hse\Domain\Enums\Jurisdiction;
use Modules\Hse\Domain\Exceptions\HseException;

/**
 * Aggregate root: what a job position, work center or construction site demands before a worker may be dispatched.
 *
 * @property string $name
 * @property Jurisdiction $jurisdiction
 * @property string|null $job_position_id
 * @property string|null $work_center_id
 * @property string|null $construction_site_id
 * @property string|null $suva_hazard_class
 * @property string|null $dvr_risk_profile_code
 * @property list<array{type: string, validity_months?: int|null}>|null $required_training_types_json
 * @property list<string>|null $required_medical_exam_codes_json
 * @property list<string>|null $required_ppe_product_ids_json
 * @property bool $is_active
 * @property int $version
 */
final class SafetyRoleRequirementMatrix extends BaseEnterpriseModel
{
    protected $table = 'hse_requirement_matrices';

    protected static function booted(): void
    {
        // Invariant: at least one target scope, and optimistic concurrency through the version counter.
        self::saving(static function (self $matrix): void {
            if ($matrix->job_position_id === null && $matrix->work_center_id === null && $matrix->construction_site_id === null) {
                throw new HseException('A requirement matrix needs a job position, a work center or a construction site.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'jurisdiction' => Jurisdiction::class,
            'required_training_types_json' => 'array',
            'required_medical_exam_codes_json' => 'array',
            'required_ppe_product_ids_json' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * The certificate types the rule demands, with the optional maximum age (months) of the certificate.
     *
     * @return list<array{type: CertificateType, validity_months: int|null}>
     */
    public function requiredTrainings(): array
    {
        $required = [];

        foreach ($this->required_training_types_json ?? [] as $entry) {
            $type = CertificateType::tryFrom($entry['type']);

            if ($type !== null) {
                $required[] = ['type' => $type, 'validity_months' => isset($entry['validity_months']) ? (int) $entry['validity_months'] : null];
            }
        }

        return $required;
    }
}
