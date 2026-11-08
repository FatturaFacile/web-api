<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The settlement of one cost element (material, labor, overhead, subcontract) of a closed production order: standard
 * cost of the output received, actual cost charged, variance and the journal entry that posted it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $production_order_id
 * @property string $cost_type
 * @property string $standard_cost
 * @property string $actual_cost
 * @property string $variance
 * @property bool $is_posted_to_gl
 * @property string|null $gl_journal_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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

    /**
     * @return BelongsTo<MfgProductionOrder, $this>
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }
}
