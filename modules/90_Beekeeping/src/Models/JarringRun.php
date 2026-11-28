<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A jarring run: honey of one extraction batch packed in jars of one format under a jar lot code, with the stock it consumed.
 *
 * @property string $extraction_batch_id
 * @property string $jar_lot_code
 * @property Carbon $jarred_on
 * @property int $jar_format_g
 * @property int $jars_count
 * @property string $net_kg
 * @property Carbon $best_before
 * @property string $origin_country
 * @property string|null $warehouse_id
 * @property string|null $jar_product_id
 * @property string|null $cap_product_id
 * @property string|null $label_product_id
 * @property list<string>|null $stock_movements
 */
final class JarringRun extends BaseEnterpriseModel
{
    protected $table = 'bee_jarring_runs';

    protected function casts(): array
    {
        return [
            'jarred_on' => 'date',
            'jar_format_g' => 'integer',
            'jars_count' => 'integer',
            'net_kg' => 'decimal:2',
            'best_before' => 'date',
            'stock_movements' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ExtractionBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ExtractionBatch::class, 'extraction_batch_id');
    }
}
