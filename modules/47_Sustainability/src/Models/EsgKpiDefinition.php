<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An ESG indicator (environmental, social or governance) measured once per reporting period.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $pillar
 * @property string $unit
 * @property string $direction
 * @property string|null $esrs_reference
 * @property string|null $target_value
 * @property bool $is_example
 * @property bool $is_active
 * @property string|null $description
 */
final class EsgKpiDefinition extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Pillars = ['environmental', 'social', 'governance'];

    /** @var list<string> */
    public const array Directions = ['higher_is_better', 'lower_is_better'];

    protected $table = 'esg_kpi_definitions';

    protected function casts(): array
    {
        return [
            'is_example' => 'boolean',
            'target_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EsgKpiValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(EsgKpiValue::class, 'kpi_definition_id');
    }
}
