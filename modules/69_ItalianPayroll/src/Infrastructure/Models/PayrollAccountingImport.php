<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * The accounting summary (prospetto contabile) of a month as the consultant reports it, and the journal entry it posted.
 *
 * @property string $id
 * @property string $payroll_period_id
 * @property string $total_gross_wages
 * @property string $inps_employee_withholding
 * @property string $inps_employer_contribution
 * @property string $irpef_withholding
 * @property string $regional_municipal_addizionali
 * @property string $tfr_monthly_accrual
 * @property string $tfr_fondo_tesoreria_or_previdenza
 * @property string $inail_accrual
 * @property string $net_payable
 * @property string|null $gl_journal_entry_id
 */
final class PayrollAccountingImport extends BaseEnterpriseModel
{
    protected $table = 'ipp_accounting_imports';
}
