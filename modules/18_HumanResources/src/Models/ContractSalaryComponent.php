<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\ContractSalaryComponentFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring pay element of an employment contract (base salary supplement, allowance, fixed deduction), identified by
 * the payroll wage type code it is paid with. Monthly components enter every payroll run of their validity; annual ones
 * are informational for cost planning.
 *
 * @property string $amount decimal string
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 */
#[UseFactory(ContractSalaryComponentFactory::class)]
final class ContractSalaryComponent extends BaseEnterpriseModel
{
    public const string FrequencyMonthly = 'monthly';

    public const string FrequencyAnnual = 'annual';

    protected $table = 'hr_contract_salary_components';

    /**
     * The immutable ledger records that a component changed, never the amount.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['amount']);

        return $attributes;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
        ];
    }

    /**
     * The contract the component belongs to.
     *
     * @return BelongsTo<EmploymentContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'contract_id');
    }
}
