<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MfgMrpPegging extends BaseEnterpriseModel
{
    protected $table = 'mfg_mrp_peggings';

    protected function casts(): array
    {
        return [
            'pegged_quantity' => 'decimal:4',
        ];
    }

    public function plannedOrder(): BelongsTo
    {
        return $this->belongsTo(MfgMrpPlannedOrder::class, 'planned_order_id');
    }
}
