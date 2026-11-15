<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources\Payroll;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;
use Modules\HumanResources\Services\Payroll\Rappen;

/**
 * @mixin SalaryDeclaration
 */
final class SalaryDeclarationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'declaration_number' => $this->declaration_number,
            'period_year' => $this->period_year,
            'status' => $this->status,
            'payroll_run_ids' => $this->payroll_run_ids,
            'employee_count' => $this->employee_count,
            'totals' => [
                'gross' => Rappen::toDecimal($this->gross_rappen),
                'ahv_salary' => Rappen::toDecimal($this->ahv_base_rappen),
                'withholding_tax' => Rappen::toDecimal($this->withholding_tax_rappen),
                'net' => Rappen::toDecimal($this->net_rappen),
            ],
            'schema_version' => $this->schema_version,
            'xsd_validated' => $this->xsd_validated,
            'xml_sha256' => $this->xml_sha256,
            'generated_at' => $this->generated_at->toIso8601String(),
            'generated_by_user_id' => $this->generated_by_user_id,
            'download_url' => route('api.v1.hr.payroll.declarations.download', ['id' => $this->id]),
        ];
    }
}
