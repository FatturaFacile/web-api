<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The money a group of employees (a department, or everybody) can receive as merit increases in a cycle.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cycle_id
 * @property string $name
 * @property string|null $department_id
 * @property string $budget_amount
 * @property string|null $notes
 */
final class CompBudgetPool extends BaseEnterpriseModel
{
    protected $table = 'tl_comp_budget_pools';

    protected function casts(): array
    {
        return ['budget_amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<CompCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CompCycle::class, 'cycle_id');
    }

    /**
     * @return HasMany<CompProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(CompProposal::class, 'budget_pool_id');
    }
}
