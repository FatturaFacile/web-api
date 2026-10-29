<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    public function componentItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'component_item_id');
    }

    public function alternateFor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'alternate_for_id');
    }
}
