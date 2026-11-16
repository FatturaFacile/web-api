<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A sustainability topic of an assessment with its impact and financial scores.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $assessment_id
 * @property string $esrs_code
 * @property string $name
 * @property string $impact_score
 * @property string $financial_score
 * @property bool $is_material
 * @property string|null $rationale
 */
final class EsgMaterialityTopic extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array EsrsCodes = ['E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1'];

    protected $table = 'esg_materiality_topics';

    protected function casts(): array
    {
        return [
            'is_material' => 'boolean',
            'impact_score' => 'decimal:2',
            'financial_score' => 'decimal:2',
        ];
    }
}
