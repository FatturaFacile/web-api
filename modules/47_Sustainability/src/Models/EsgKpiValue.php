<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value of one KPI in one reporting period.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kpi_definition_id
 * @property string $reporting_period_id
 * @property string $value
 * @property string $data_quality
 * @property string|null $notes
 * @property string|null $recorded_by
 */
final class EsgKpiValue extends BaseEnterpriseModel
{
    protected $table = 'esg_kpi_values';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<EsgKpiDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(EsgKpiDefinition::class, 'kpi_definition_id');
    }
}
