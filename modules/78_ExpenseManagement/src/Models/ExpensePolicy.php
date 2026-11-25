<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An expense policy of a jurisdiction: duplicate detection, mandatory receipt threshold, traceable payment rules and the
 * caps held by its rules.
 *
 * @property string $code
 * @property string $name
 * @property string $jurisdiction
 * @property bool $is_active
 * @property bool $duplicate_detection
 * @property bool $block_non_traceable
 * @property string $receipt_threshold
 * @property string|null $mileage_rate_per_km
 * @property string|null $per_diem_daily_limit
 * @property int|null $max_age_days
 */
final class ExpensePolicy extends BaseEnterpriseModel
{
    use AllowsGlobalRecords;

    protected $table = 'exm_policies';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'duplicate_detection' => 'boolean',
            'block_non_traceable' => 'boolean',
            'receipt_threshold' => 'decimal:2',
            'mileage_rate_per_km' => 'decimal:4',
            'per_diem_daily_limit' => 'decimal:2',
            'max_age_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<ExpensePolicyRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(ExpensePolicyRule::class, 'policy_id');
    }
}
