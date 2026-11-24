<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Controlling record of the table controlling_budget_lines.
 *
 * @property string $tenant_id
 * @property string $nature_type
 * @property string|null $cost_center_id
 * @property string|null $job_id
 * @property string|null $product_family_id
 * @property string|null $customer_id
 * @property string|null $sales_agent_id
 * @property string|null $account_code
 * @property string $annual_total
 * @property string $target_quantity
 * @property string $month_01
 * @property string $month_02
 * @property string $month_03
 * @property string $month_04
 * @property string $month_05
 * @property string $month_06
 * @property string $month_07
 * @property string $month_08
 * @property string $month_09
 * @property string $month_10
 * @property string $month_11
 * @property string $month_12
 */
final class BudgetLine extends BaseEnterpriseModel
{
    protected $table = 'controlling_budget_lines';

    public const array Drivers = ['equal_12', 'prior_year_seasonality', 'custom_weights', 'manual'];

    /**
     * @return list<string>
     */
    public static function monthColumns(): array
    {
        return array_map(static fn (int $m): string => sprintf('month_%02d', $m), range(1, 12));
    }
}
