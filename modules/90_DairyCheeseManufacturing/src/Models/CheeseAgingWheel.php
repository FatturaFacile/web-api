<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single cheese wheel with its casein plaque serial, position, grade and dual inventory (count + weight).
 *
 * @property string $serial
 * @property string $vat_batch_id
 * @property string|null $rack_id
 * @property int|null $position
 * @property string $status
 * @property string $grade
 * @property bool $branded
 * @property Carbon|null $branded_at
 * @property string $initial_weight_kg
 * @property string|null $measured_weight_kg
 * @property Carbon|null $last_weighed_at
 * @property Carbon|null $sold_at
 * @property string|null $sold_weight_kg
 * @property string|null $sale_variance_kg
 * @property string|null $customer_id
 * @property string|null $customer_name
 * @property string|null $invoice_ref
 * @property string|null $grading_notes
 * @property-read CheeseVatBatch $batch
 * @property-read AgingRack|null $rack
 */
final class CheeseAgingWheel extends BaseEnterpriseModel
{
    protected $table = 'dairy_cheese_aging_wheels';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'branded' => 'boolean',
            'branded_at' => 'datetime',
            'initial_weight_kg' => 'decimal:3',
            'measured_weight_kg' => 'decimal:3',
            'last_weighed_at' => 'datetime',
            'sold_at' => 'datetime',
            'sold_weight_kg' => 'decimal:3',
            'sale_variance_kg' => 'decimal:3',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CheeseVatBatch::class, 'vat_batch_id');
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(AgingRack::class, 'rack_id');
    }
}
