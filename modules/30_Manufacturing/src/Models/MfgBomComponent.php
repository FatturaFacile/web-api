<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A component line of a BOM revision: quantity per base quantity of the parent, scrap and yield, phantom flag, issue
 * method (manual issue or backflush) and the routing operation that consumes it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $bom_id
 * @property string $component_item_id
 * @property int $sequence
 * @property string $quantity_per
 * @property string $uom
 * @property string $scrap_percentage
 * @property string $yield_percentage
 * @property bool $is_phantom
 * @property string $issue_method
 * @property int|null $operation_sequence
 * @property string|null $alternate_for_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgItem|null $componentItem
 */
final class MfgBomComponent extends BaseEnterpriseModel
{
    protected $table = 'mfg_bom_components';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'quantity_per' => 'decimal:4',
            'scrap_percentage' => 'decimal:2',
            'yield_percentage' => 'decimal:2',
            'is_phantom' => 'boolean',
            'operation_sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function componentItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'component_item_id');
    }

    /**
     * @return BelongsTo<MfgBomComponent, $this>
     */
    public function alternateFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'alternate_for_id');
    }
}
