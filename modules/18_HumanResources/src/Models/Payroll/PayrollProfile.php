<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Employee;

/**
 * Swiss payroll data of an employee. The AHV number is encrypted at rest and must only be exposed masked.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $employee_id
 * @property string|null $ahv_number
 * @property string $work_canton
 * @property string|null $residence_canton
 * @property string $residence_country
 * @property string|null $residence_municipality_bfs
 * @property string|null $permit_type
 * @property string $civil_status
 * @property int $children_count
 * @property bool $church_tax
 * @property string|null $qst_tariff_code
 * @property string|null $qst_canton
 * @property bool $bvg_insured
 * @property string|null $bvg_plan_code
 * @property string|null $uvg_class
 * @property bool $ktg_insured
 * @property array<string, mixed>|null $metadata
 * @property-read Employee|null $employee
 */
final class PayrollProfile extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_payroll_profiles';

    protected $hidden = ['ahv_number'];

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function maskedAhvNumber(): ?string
    {
        $ahv = $this->ahv_number;

        if ($ahv === null || $ahv === '') {
            return null;
        }

        return '756.****.****.'.substr($ahv, -2);
    }

    public function isSubjectToWithholdingTax(): bool
    {
        return $this->qst_tariff_code !== null && $this->qst_tariff_code !== '';
    }

    protected function casts(): array
    {
        return [
            'ahv_number' => 'encrypted',
            'children_count' => 'integer',
            'church_tax' => 'boolean',
            'bvg_insured' => 'boolean',
            'ktg_insured' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
