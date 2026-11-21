<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Domain\Enums;

/**
 * The accounts of the payroll posting, chosen per tenant from its chart of accounts (the application ships no account
 * code): ipp_gl_account_settings maps each role to an account.
 */
enum GlRole: string
{
    case GrossWages = 'gross_wages';
    case EmployerInps = 'employer_inps';
    case TfrAccrual = 'tfr_accrual';
    case NetPayable = 'net_payable';
    case InpsPayable = 'inps_payable';
    case IrpefPayable = 'irpef_payable';
    case AddizionaliPayable = 'addizionali_payable';
    case TfrFund = 'tfr_fund';

    public function isDebit(): bool
    {
        return in_array($this, [self::GrossWages, self::EmployerInps, self::TfrAccrual], true);
    }
}
