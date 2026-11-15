<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A planning driver: a quantity times a rate (headcount times salary, units times unit cost) spread over a range of
 * periods by an allocation rule. Generating the driver writes its budget lines.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $version_id
 * @property string $name
 * @property string $account_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property string $quantity
 * @property string $rate
 * @property string $spread_method
 * @property list<string>|null $percentages
 * @property int $start_period
 * @property int $end_period
 * @property bool $is_active
 */
final class BudgetDriver extends BaseEnterpriseModel
{
    use ProtectsRecords;

    public const string Even = 'even';

    public const string Seasonal = 'seasonal';

    public const string Percentage = 'percentage';

    public const array SpreadMethods = [self::Even, self::Seasonal, self::Percentage];

    protected $table = 'budget_drivers';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'rate' => 'decimal:4',
            'percentages' => 'array',
            'start_period' => 'integer',
            'end_period' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        $status = $this->version_id === null ? null : BudgetVersion::query()->whereKey($this->version_id)->value('status');

        return $status !== null && $status !== BudgetVersion::Draft ? __('budgeting_errors.version.not_draft') : null;
    }
}
