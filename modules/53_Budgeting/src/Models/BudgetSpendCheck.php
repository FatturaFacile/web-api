<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The log of a spend control that did not simply pass: a warning, a block, or a block lifted by an override.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $source_number
 * @property string $stage
 * @property string $outcome
 * @property string|null $rule_id
 * @property string|null $override_id
 * @property string|null $account_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property int $fiscal_year
 * @property string $budget_amount
 * @property string $actual_amount
 * @property string $committed_amount
 * @property string $requested_amount
 * @property string $available_amount
 * @property string|null $projected_percent
 * @property string|null $user_id
 * @property string|null $message
 */
final class BudgetSpendCheck extends BaseEnterpriseModel
{
    public const string Warning = 'warning';

    public const string Blocked = 'blocked';

    public const string Overridden = 'overridden';

    protected $table = 'budget_spend_checks';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'budget_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'committed_amount' => 'decimal:2',
            'requested_amount' => 'decimal:2',
            'available_amount' => 'decimal:2',
            'projected_percent' => 'decimal:2',
        ];
    }
}
