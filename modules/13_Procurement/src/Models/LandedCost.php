<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An additional cost of purchased goods (freight, duty, insurance...) allocated to goods receipt lines by value,
 * weight or quantity and capitalized into the inventory value when posted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $landed_cost_number
 * @property string $cost_type
 * @property string $allocation_method
 * @property string $status
 * @property string $amount
 * @property string $currency
 * @property string|null $passive_invoice_id
 * @property string|null $supplier_id
 * @property Carbon $cost_date
 * @property string|null $description
 * @property Carbon|null $posted_at
 * @property string|null $journal_entry_id
 * @property string|null $created_by
 */
final class LandedCost extends BaseEnterpriseModel
{
    protected $table = 'procurement_landed_costs';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cost_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LandedCostAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class, 'landed_cost_id');
    }
}
