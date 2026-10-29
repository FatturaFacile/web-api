<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MfgMrpShortage extends BaseEnterpriseModel
{
    protected $table = 'mfg_mrp_shortages';

    protected function casts(): array
    {
        return [
            'required_date' => 'date',
            'gross_requirement' => 'decimal:4',
            'available_inventory' => 'decimal:4',
            'scheduled_receipts' => 'decimal:4',
            'safety_stock' => 'decimal:4',
            'net_shortage' => 'decimal:4',
        ];
    }

    public function mrpRun(): BelongsTo
    {
        return $this->belongsTo(MfgMrpRun::class, 'mrp_run_id');
    }

    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }
}
