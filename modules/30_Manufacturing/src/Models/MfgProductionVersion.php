<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A production version: a BOM and routing pair valid for an item in a date range and lot size range.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $version_code
 * @property string $mfg_item_id
 * @property string $bom_id
 * @property string $routing_id
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string $min_lot_size
 * @property string|null $max_lot_size
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgProductionVersion extends BaseEnterpriseModel
{
    protected $table = 'mfg_production_versions';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'min_lot_size' => 'decimal:4',
            'max_lot_size' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    /**
     * @return BelongsTo<MfgRouting, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }
}
