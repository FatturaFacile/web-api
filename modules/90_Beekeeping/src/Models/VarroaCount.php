<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mite drop count of a hive (natural drop or after a treatment), in mites per day.
 *
 * @property string $hive_id
 * @property string|null $treatment_id
 * @property Carbon $counted_on
 * @property string $count_type
 * @property string $mites_per_day
 */
final class VarroaCount extends BaseEnterpriseModel
{
    protected $table = 'bee_varroa_counts';

    protected function casts(): array
    {
        return [
            'counted_on' => 'date',
            'mites_per_day' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Hive, $this>
     */
    public function hive(): BelongsTo
    {
        return $this->belongsTo(Hive::class, 'hive_id');
    }
}
