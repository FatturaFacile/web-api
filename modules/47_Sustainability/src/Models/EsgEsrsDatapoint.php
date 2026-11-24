<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CSRD/ESRS data point of the reporting catalog: the standard it belongs to (ESRS 2, E1-E5, S1-S4, G1), the disclosure
 * requirement, its data type and unit. A data point with an auto source is filled from the carbon inventory.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $standard
 * @property string $disclosure
 * @property string $name
 * @property string|null $description
 * @property string $data_type
 * @property string|null $unit
 * @property bool $is_mandatory
 * @property string|null $auto_source
 * @property int $sort_order
 * @property bool $is_active
 */
final class EsgEsrsDatapoint extends BaseEnterpriseModel
{
    /** Standards covered by the catalog. ESRS 2 (general disclosures) applies to every undertaking. */
    public const array Standards = ['ESRS2', 'E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1'];

    public const array DataTypes = ['number', 'text', 'boolean'];

    /** Values the inventory can provide to a data point. */
    public const array AutoSources = ['inventory.scope_1_tco2e', 'inventory.scope_2_tco2e', 'inventory.scope_3_tco2e', 'inventory.total_tco2e', 'inventory.intensity_revenue', 'period.employees_fte'];

    protected $table = 'esg_esrs_datapoints';

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<EsgEsrsDatapointValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(EsgEsrsDatapointValue::class, 'datapoint_id');
    }
}
