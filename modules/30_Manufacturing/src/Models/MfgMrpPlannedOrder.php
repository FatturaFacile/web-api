<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MfgMrpPlannedOrder extends BaseEnterpriseModel
{
    protected $table = 'mfg_mrp_planned_orders';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'start_date' => 'date',
            'due_date' => 'date',
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

    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }

    public function peggings(): HasMany
    {
        return $this->hasMany(MfgMrpPegging::class, 'planned_order_id');
    }

    public function isMake(): bool
    {
        return $this->order_type === 'make';
    }

    public function isBuy(): bool
    {
        return $this->order_type === 'buy';
    }
}
