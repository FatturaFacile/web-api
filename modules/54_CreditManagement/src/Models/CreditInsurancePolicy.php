<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A credit insurance policy: the insurer covers a percentage of the insured receivables of the customers it grants a
 * cover for, up to the total cover of the policy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $insurer
 * @property string $policy_number
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $currency
 * @property string $coverage_percentage
 * @property string|null $max_total_cover
 * @property string $status
 * @property string|null $notes
 */
final class CreditInsurancePolicy extends BaseEnterpriseModel
{
    public const string Active = 'active';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Active, self::Cancelled];

    protected $table = 'credit_insurance_policies';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'coverage_percentage' => 'decimal:2',
            'max_total_cover' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<CreditInsuranceCover, $this>
     */
    public function covers(): HasMany
    {
        return $this->hasMany(CreditInsuranceCover::class, 'policy_id');
    }

    /**
     * Whether the policy is in force on the given day.
     */
    public function isInForceOn(Carbon $day): bool
    {
        return $this->status === self::Active && $this->starts_on->lte($day) && $this->ends_on->gte($day);
    }
}
