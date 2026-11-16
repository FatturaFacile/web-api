<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models\Payroll;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Generated ELM-style salary declaration. The XML lives on `xml_disk` at `xml_path`; `xml_sha256` lets the
 * stored file be verified before it is transmitted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $declaration_number
 * @property int $period_year
 * @property string $status
 * @property list<string> $payroll_run_ids
 * @property int $employee_count
 * @property int $gross_rappen
 * @property int $ahv_base_rappen
 * @property int $withholding_tax_rappen
 * @property int $net_rappen
 * @property string $xml_disk
 * @property string $xml_path
 * @property string $xml_sha256
 * @property string $schema_version
 * @property bool $xsd_validated
 * @property \Illuminate\Support\Carbon $generated_at
 * @property string|null $generated_by_user_id
 */
final class SalaryDeclaration extends BaseEnterpriseModel
{
    protected $table = 'hr_ch_salary_declarations';

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'payroll_run_ids' => 'array',
            'employee_count' => 'integer',
            'gross_rappen' => 'integer',
            'ahv_base_rappen' => 'integer',
            'withholding_tax_rappen' => 'integer',
            'net_rappen' => 'integer',
            'xsd_validated' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }
}
