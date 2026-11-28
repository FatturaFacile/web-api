<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Honey supers harvested from an apiary and delivered to the extraction room (ingresso melari).
 *
 * @property string $apiary_id
 * @property string|null $extraction_batch_id
 * @property Carbon $harvested_on
 * @property string $botanical_origin
 * @property int $supers_count
 * @property string $gross_weight_kg
 * @property string $net_weight_kg
 * @property string|null $notes
 */
final class SuperIntake extends BaseEnterpriseModel
{
    protected $table = 'bee_super_intakes';

    protected function casts(): array
    {
        return [
            'harvested_on' => 'date',
            'supers_count' => 'integer',
            'gross_weight_kg' => 'decimal:2',
            'net_weight_kg' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Apiary, $this>
     */
    public function apiary(): BelongsTo
    {
        return $this->belongsTo(Apiary::class, 'apiary_id');
    }

    /**
     * @return BelongsTo<ExtractionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExtractionBatch::class, 'extraction_batch_id');
    }
}
