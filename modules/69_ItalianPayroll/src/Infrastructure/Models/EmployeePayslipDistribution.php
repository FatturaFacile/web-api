<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The delivery of the payslip (and the annual CU) to one employee, with the first-view receipt that L. 4/1953 asks for.
 *
 * @property string $id
 * @property string $payroll_period_id
 * @property string $employee_id
 * @property string|null $cedolino_pdf_document_id
 * @property string|null $cu_pdf_document_id
 * @property string $net_amount
 * @property CarbonImmutable|null $viewed_by_employee_at
 * @property string|null $viewed_from_ip
 */
final class EmployeePayslipDistribution extends BaseEnterpriseModel
{
    protected $table = 'ipp_payslip_distributions';

    protected function casts(): array
    {
        return ['viewed_by_employee_at' => 'immutable_datetime'];
    }
}
