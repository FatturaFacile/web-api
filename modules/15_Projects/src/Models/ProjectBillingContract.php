<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Projects\Support\BillingModel;
use Modules\Projects\Support\Money;

/**
 * The billing contract of a project (Mod 081): billing model, contract value, advance payment, guarantee retention,
 * T&M cap and markup, prepaid retainer allowance and the fiscal jurisdiction that selects the IT or CH billing strategy.
 * `version` is the optimistic concurrency token: every update must present the version it read.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string $customer_id
 * @property string $billing_model
 * @property string $fiscal_jurisdiction
 * @property string $currency_code
 * @property string $contract_total_value
 * @property string $advance_payment_percent
 * @property string $advance_payment_amount
 * @property string $advance_recouped_amount
 * @property string $retention_guarantee_percent
 * @property string $retention_release_rule
 * @property CarbonInterface|null $retention_release_date
 * @property string|null $cap_amount
 * @property string $markup_percent
 * @property string|null $rate_card_id
 * @property string $prepaid_hours_allowance
 * @property string $prepaid_amount_allowance
 * @property string $low_balance_alert_threshold_percent
 * @property CarbonInterface|null $low_balance_alerted_at
 * @property string|null $cig
 * @property string|null $cup
 * @property string|null $contract_reference
 * @property CarbonInterface|null $contract_date
 * @property string $billing_status
 * @property int $version
 */
final class ProjectBillingContract extends BaseEnterpriseModel
{
    protected $table = 'project_billing_contracts';

    protected function casts(): array
    {
        return [
            'contract_total_value' => 'decimal:4',
            'advance_payment_percent' => 'decimal:4',
            'advance_payment_amount' => 'decimal:4',
            'advance_recouped_amount' => 'decimal:4',
            'retention_guarantee_percent' => 'decimal:4',
            'retention_release_date' => 'date',
            'cap_amount' => 'decimal:4',
            'markup_percent' => 'decimal:4',
            'prepaid_hours_allowance' => 'decimal:4',
            'prepaid_amount_allowance' => 'decimal:4',
            'low_balance_alert_threshold_percent' => 'decimal:4',
            'low_balance_alerted_at' => 'datetime',
            'contract_date' => 'date',
            'version' => 'integer',
        ];
    }

    public function model(): BillingModel
    {
        return BillingModel::from($this->billing_model);
    }

    public function isBillable(): bool
    {
        return $this->billing_status === 'active';
    }

    /**
     * Total advance to recoup: the fixed amount, else the percentage of the contract value.
     */
    public function advanceTotal(): string
    {
        return Money::isPositive($this->advance_payment_amount)
            ? Money::of($this->advance_payment_amount)
            : Money::div(Money::mul($this->contract_total_value, $this->advance_payment_percent), 100) ?? '0';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectSalCertificate, $this>
     */
    public function salCertificates(): HasMany
    {
        return $this->hasMany(ProjectSalCertificate::class, 'billing_contract_id')->orderBy('sal_number');
    }

    /**
     * @return HasMany<ProjectRetainerLedgerEntry, $this>
     */
    public function retainerEntries(): HasMany
    {
        return $this->hasMany(ProjectRetainerLedgerEntry::class, 'billing_contract_id');
    }

    /**
     * @return HasMany<ProjectRetentionLedgerEntry, $this>
     */
    public function retentionEntries(): HasMany
    {
        return $this->hasMany(ProjectRetentionLedgerEntry::class, 'billing_contract_id');
    }
}
