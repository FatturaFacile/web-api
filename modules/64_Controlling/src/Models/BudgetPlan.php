<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Controlling record of the table controlling_budget_plans.
 *
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property int $fiscal_year
 * @property string $plan_type
 * @property int $version_number
 * @property int|null $cutoff_month
 * @property string $status
 * @property string $currency_code
 * @property string|null $notes
 */
final class BudgetPlan extends BaseEnterpriseModel
{
    protected $table = 'controlling_budget_plans';

    public const array Types = ['annual_budget', 'rolling_forecast', 'what_if_scenario'];

    public const array Statuses = ['draft', 'submitted', 'approved', 'locked', 'archived'];

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'plan_id');
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'fiscal_year' => 'integer', 'cutoff_month' => 'integer', 'version_number' => 'integer'];
    }
}
