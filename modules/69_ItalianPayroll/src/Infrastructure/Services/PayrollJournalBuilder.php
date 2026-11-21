<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Services;

use Modules\ItalianPayroll\Domain\Enums\GlRole;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\Payroll\Calculation\Dec;

/**
 * Turns the accounting summary of the consultant into balanced journal lines (Dare/Avere). The summary must reconcile
 * before anything is posted: the gross wages must equal the net payable plus the employee INPS, IRPEF and addizionali.
 */
final class PayrollJournalBuilder
{
    /**
     * @param  array{gross: string, inps_employee: string, inps_employer: string, irpef: string, addizionali: string, tfr_accrual: string, tfr_fund: string, net: string}  $summary
     */
    public function assertReconciles(array $summary): void
    {
        $deductions = Dec::sum([$summary['inps_employee'], $summary['irpef'], $summary['addizionali']]);
        $expectedNet = Dec::sub($summary['gross'], $deductions);

        if (Dec::cmp($expectedNet, $summary['net']) !== 0) {
            throw new ItalianPayrollException("Il prospetto contabile non quadra: netto atteso {$expectedNet}, indicato {$summary['net']}.");
        }

        if (Dec::cmp($summary['tfr_accrual'], $summary['tfr_fund']) !== 0) {
            throw new ItalianPayrollException('Il prospetto contabile non quadra: accantonamento TFR e fondo TFR devono coincidere.');
        }
    }

    /**
     * @param  array{gross: string, inps_employee: string, inps_employer: string, irpef: string, addizionali: string, tfr_accrual: string, tfr_fund: string, net: string}  $summary
     * @param  array<string, string>  $accounts  GlRole value => account id
     * @return list<array{account_id: string, debit_amount: string, credit_amount: string, description: string}>
     */
    public function lines(array $summary, array $accounts): array
    {
        $this->assertReconciles($summary);

        $amounts = [
            GlRole::GrossWages->value => [$summary['gross'], 'Salari e stipendi lordi'],
            GlRole::EmployerInps->value => [$summary['inps_employer'], 'Oneri sociali ditta'],
            GlRole::TfrAccrual->value => [$summary['tfr_accrual'], 'Accantonamento TFR'],
            GlRole::NetPayable->value => [$summary['net'], 'Dipendenti c/retribuzioni'],
            GlRole::InpsPayable->value => [Dec::add($summary['inps_employer'], $summary['inps_employee']), 'INPS c/competenze'],
            GlRole::IrpefPayable->value => [$summary['irpef'], 'Erario c/ritenute IRPEF'],
            GlRole::AddizionaliPayable->value => [$summary['addizionali'], 'Erario c/addizionali regionali/comunali'],
            GlRole::TfrFund->value => [$summary['tfr_fund'], 'Fondo TFR / fondo tesoreria INPS'],
        ];

        $lines = [];
        $debit = '0';
        $credit = '0';

        foreach (GlRole::cases() as $role) {
            [$amount, $description] = $amounts[$role->value];

            if (! Dec::isPositive($amount)) {
                continue;
            }

            $accountId = $accounts[$role->value] ?? throw new ItalianPayrollException("Nessun conto configurato per il ruolo {$role->value}.");
            $lines[] = [
                'account_id' => $accountId,
                'debit_amount' => $role->isDebit() ? $amount : '0.00',
                'credit_amount' => $role->isDebit() ? '0.00' : $amount,
                'description' => $description,
            ];
            $role->isDebit() ? $debit = Dec::add($debit, $amount) : $credit = Dec::add($credit, $amount);
        }

        if (Dec::cmp($debit, $credit) !== 0) {
            throw new ItalianPayrollException("La scrittura contabile non quadra: dare {$debit}, avere {$credit}.");
        }

        return $lines;
    }
}
