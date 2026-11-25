<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;

/**
 * A cap of a policy: per item or per day (and employee), on one category or on all of them.
 *
 * @property string $policy_id
 * @property string|null $category_code
 * @property string $rule_type max_per_item | max_per_day
 * @property string $limit_amount
 * @property string $severity warning | blocking
 */
final class ExpensePolicyRule extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    public const string MaxPerItem = 'max_per_item';

    public const string MaxPerDay = 'max_per_day';

    protected $table = 'exm_policy_rules';

    protected function casts(): array
    {
        return ['limit_amount' => 'decimal:2'];
    }
}
