<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The quantity actually consumed or sold on an account (optionally a cost centre and a project) in a calendar month.
 * The ledger holds amounts only; with this quantity the variance is split into a volume and a price effect.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property string $month
 * @property string $quantity
 * @property string|null $notes
 */
final class BudgetActualVolume extends BaseEnterpriseModel
{
    protected $table = 'budget_actual_volumes';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
