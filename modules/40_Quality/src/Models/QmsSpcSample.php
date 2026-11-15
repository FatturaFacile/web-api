<?php

declare(strict_types=1);

namespace Modules\Quality\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rational subgroup of measurements of one characteristic: the mean and the range are computed on save.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $characteristic_id
 * @property Carbon $sampled_at
 * @property string|null $lot
 * @property list<float> $measurements
 * @property string $mean
 * @property string $range
 * @property string|null $instrument_id
 * @property string|null $recorded_by
 */
final class QmsSpcSample extends BaseEnterpriseModel
{
    protected $table = 'qms_spc_samples';

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'measurements' => 'array',
            'mean' => 'decimal:5',
            'range' => 'decimal:5',
        ];
    }

    /**
     * @return BelongsTo<QmsSpcCharacteristic, $this>
     */
    public function characteristic(): BelongsTo
    {
        return $this->belongsTo(QmsSpcCharacteristic::class, 'characteristic_id');
    }
}
