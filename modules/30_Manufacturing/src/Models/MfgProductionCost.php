<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MfgProductionCost extends BaseEnterpriseModel
{
    protected $table = 'mfg_production_costs';

    protected function casts(): array
    {
        return [
            'standard_cost' => 'decimal:4',
            'actual_cost' => 'decimal:4',
            'variance' => 'decimal:4',
            'is_posted_to_gl' => 'boolean',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }
}
