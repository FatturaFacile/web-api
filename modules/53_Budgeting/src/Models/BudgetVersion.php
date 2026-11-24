<?php

declare(strict_types=1);

namespace Modules\Budgeting\Models;

use App\Support\Database\Concerns\ProtectsRecords;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A budget version of a fiscal year: the budget, a forecast or a rolling forecast. A version runs through draft,
 * submitted, approved and locked; only a draft takes changes, and a locked version is immutable.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int $fiscal_year
 * @property Carbon $year_start
 * @property string $version_type
 * @property string $status
 * @property string $currency
 * @property string|null $base_version_id
 * @property int|null $reforecast_from_period
 * @property list<string>|null $seasonal_weights
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $locked_by
 * @property Carbon|null $locked_at
 */
final class BudgetVersion extends BaseEnterpriseModel
{
    use ProtectsRecords;

    public const string Budget = 'budget';

    public const string Forecast = 'forecast';

    public const string RollingForecast = 'rolling_forecast';

    public const array Types = [self::Budget, self::Forecast, self::RollingForecast];

    public const string Draft = 'draft';

    public const string Submitted = 'submitted';

    public const string Approved = 'approved';

    public const string Locked = 'locked';

    public const array Statuses = [self::Draft, self::Submitted, self::Approved, self::Locked];

    protected $table = 'budget_versions';

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'year_start' => 'date',
            'reforecast_from_period' => 'integer',
            'seasonal_weights' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::Draft;
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'version_id');
    }

    /**
     * @return HasMany<BudgetDriver, $this>
     */
    public function drivers(): HasMany
    {
        return $this->hasMany(BudgetDriver::class, 'version_id');
    }

    /**
     * @return HasMany<BudgetApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(BudgetApproval::class, 'version_id')->orderBy('created_at')->orderBy('id');
    }

    protected function recordProtectionViolation(string $operation): ?string
    {
        if ($operation !== 'update' || $this->getOriginal('status') !== self::Locked) {
            return null;
        }

        return __('budgeting_errors.version.locked');
    }
}
