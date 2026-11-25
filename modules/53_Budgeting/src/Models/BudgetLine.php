<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The amount budgeted on an account (optionally a cost centre and a project) in a fiscal period (1 to 12) of a version.
 * Lines of a version that is not a draft cannot be created, changed or removed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $version_id
 * @property string $account_id
 * @property string|null $cost_center_id
 * @property string|null $project_id
 * @property int $period
 * @property string $amount
 * @property string|null $quantity
 * @property string|null $unit_price
 * @property string $source
 * @property string|null $driver_id
 * @property string|null $notes
 */
final class BudgetLine extends BaseEnterpriseModel
{
    use ProtectsRecords;

    public const string Manual = 'manual';

    public const string Driver = 'driver';

    public const string Spread = 'spread';

    public const string Reforecast = 'reforecast';

    public const string Actual = 'actual';

    public const array Sources = [self::Manual, self::Driver, self::Spread, self::Reforecast, self::Actual];

    protected $table = 'budget_lines';

    protected function casts(): array
    {
        return [
            'period' => 'integer',
            'amount' => 'decimal:2',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<BudgetVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'version_id');
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        $status = BudgetVersion::query()->whereKey($this->version_id)->value('status');

        return $status !== null && $status !== BudgetVersion::Draft ? __('budgeting_errors.version.not_draft') : null;
    }
}
