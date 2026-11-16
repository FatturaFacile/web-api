<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lease of the company as lessee. Before activation it is a draft with the terms of the contract; activation measures
 * the lease liability and the right-of-use asset and writes the first schedule version. The accounting standard
 * (IFRS 16, OIC, Swiss GAAP FER) is recorded for disclosure; the measurement is the same.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $title
 * @property string $lessor_name
 * @property string|null $lessor_contact_id
 * @property string $asset_class
 * @property string|null $asset_description
 * @property string|null $fixed_asset_id
 * @property string $accounting_standard
 * @property string $currency
 * @property Carbon $commencement_date
 * @property int $term_months
 * @property string $payment_amount
 * @property string $payment_frequency
 * @property string $payment_timing
 * @property string $escalation_type
 * @property string $escalation_rate_percent
 * @property string $incremental_borrowing_rate_percent
 * @property string $initial_direct_costs
 * @property string $incentives_received
 * @property string|null $purchase_option_price
 * @property bool $purchase_option_reasonably_certain
 * @property int|null $extension_months
 * @property bool $extension_reasonably_certain
 * @property int|null $useful_life_months
 * @property string $exemption
 * @property string|null $underlying_asset_value
 * @property string|null $rou_asset_account_id
 * @property string|null $accumulated_depreciation_account_id
 * @property string|null $lease_liability_account_id
 * @property string|null $interest_expense_account_id
 * @property string|null $depreciation_expense_account_id
 * @property string $status
 * @property int $current_version_number
 * @property Carbon|null $activated_at
 * @property string|null $activated_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property string|null $notes
 * @property string|null $created_by
 */
final class LeaseContract extends BaseEnterpriseModel
{
    public const array AssetClasses = ['real_estate', 'vehicles', 'machinery', 'it_equipment', 'other'];

    public const array Standards = ['ifrs16', 'oic', 'swiss_gaap_fer'];

    /** Months between two payments, per frequency. */
    public const array FrequencyMonths = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

    public const array Timings = ['advance', 'arrears'];

    /** `fixed`: a known annual step-up measured from the start; `index`: indexation measured when the index changes. */
    public const array EscalationTypes = ['none', 'fixed', 'index'];

    public const array Exemptions = ['none', 'short_term', 'low_value'];

    public const array Statuses = ['draft', 'active', 'expired', 'cancelled'];

    /** The life of a lease: allowed next statuses per status. */
    public const array Transitions = [
        'draft' => ['active', 'cancelled'],
        'active' => ['expired'],
        'expired' => [],
        'cancelled' => [],
    ];

    protected $table = 'lease_contracts';

    protected function casts(): array
    {
        return [
            'commencement_date' => 'date',
            'term_months' => 'integer',
            'payment_amount' => 'decimal:2',
            'escalation_rate_percent' => 'decimal:6',
            'incremental_borrowing_rate_percent' => 'decimal:6',
            'initial_direct_costs' => 'decimal:2',
            'incentives_received' => 'decimal:2',
            'purchase_option_price' => 'decimal:2',
            'purchase_option_reasonably_certain' => 'boolean',
            'extension_months' => 'integer',
            'extension_reasonably_certain' => 'boolean',
            'useful_life_months' => 'integer',
            'underlying_asset_value' => 'decimal:2',
            'current_version_number' => 'integer',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LeaseScheduleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(LeaseScheduleVersion::class, 'lease_contract_id')->orderBy('version_number');
    }

    /**
     * Rows of the schedule in force: every row that no remeasurement replaced, in period order.
     *
     * @return HasMany<LeaseScheduleLine, $this>
     */
    public function currentLines(): HasMany
    {
        return $this->hasMany(LeaseScheduleLine::class, 'lease_contract_id')->where('status', '!=', 'superseded')->orderBy('period_number');
    }

    /**
     * @return HasMany<LeasePeriodPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(LeasePeriodPosting::class, 'lease_contract_id')->orderBy('period_number');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::Transitions[$this->status] ?? [], true);
    }

    public function isExempt(): bool
    {
        return $this->exemption !== 'none';
    }

    public function paymentFrequencyMonths(): int
    {
        return self::FrequencyMonths[$this->payment_frequency];
    }

    /**
     * Months of the lease the company is reasonably certain to use: the term and the extension it will exercise.
     */
    public function effectiveTermMonths(): int
    {
        return $this->term_months + ($this->extension_reasonably_certain ? (int) $this->extension_months : 0);
    }

    public function commencement(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->commencement_date->toDateString());
    }
}
