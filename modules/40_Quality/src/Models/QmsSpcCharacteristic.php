<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A measured product or process characteristic with its specification limits, tracked on an X-bar/R control chart.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $unit
 * @property string|null $lower_spec_limit
 * @property string|null $upper_spec_limit
 * @property string|null $target
 * @property int $subgroup_size
 * @property string|null $instrument_id
 * @property string $status
 * @property string|null $notes
 */
final class QmsSpcCharacteristic extends BaseEnterpriseModel
{
    public const array Statuses = ['active', 'archived'];

    protected $table = 'qms_spc_characteristics';

    protected function casts(): array
    {
        return [
            'lower_spec_limit' => 'decimal:5',
            'upper_spec_limit' => 'decimal:5',
            'target' => 'decimal:5',
            'subgroup_size' => 'integer',
        ];
    }

    /**
     * @return HasMany<QmsSpcSample, $this>
     */
    public function samples(): HasMany
    {
        return $this->hasMany(QmsSpcSample::class, 'characteristic_id')->orderBy('sampled_at');
    }
}
