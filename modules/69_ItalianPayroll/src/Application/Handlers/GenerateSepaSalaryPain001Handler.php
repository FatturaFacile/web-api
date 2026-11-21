<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Handlers;

use Modules\HumanResources\Models\Employee;
use Modules\ItalianPayroll\Application\Commands\GenerateSepaSalaryPain001Command;
use Modules\ItalianPayroll\Domain\Enums\PeriodStatus;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Export\ExportFile;
use Modules\ItalianPayroll\Infrastructure\Models\EmployeePayslipDistribution;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\PayrollAccountingImport;
use Modules\ItalianPayroll\Infrastructure\Sepa\SalaryPain001Generator;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Models\PayrollEmployeeProfile;

/**
 * Builds the SEPA salary file from the employee nets of the imported accounting summary. The file is refused unless the
 * nets add up exactly to the net payable that was posted to the ledger, and every payee has an IBAN.
 */
final class GenerateSepaSalaryPain001Handler
{
    public function __construct(private readonly SalaryPain001Generator $generator) {}

    public function handle(GenerateSepaSalaryPain001Command $command): ExportFile
    {
        $period = ItalianPayrollPeriod::query()->findOrFail($command->periodId);

        if ($period->status->rank() < PeriodStatus::CedoliniImported->rank()) {
            throw new ItalianPayrollException('Gli stipendi si dispongono dopo l\'importazione di cedolini e prospetto contabile.');
        }

        $import = PayrollAccountingImport::query()->where('payroll_period_id', $period->id)->first()
            ?? throw new ItalianPayrollException('Importare il prospetto contabile prima di generare il file SEPA.');
        $distributions = EmployeePayslipDistribution::query()->where('payroll_period_id', $period->id)->where('net_amount', '>', 0)->get();
        $total = Dec::sum($distributions->map(fn (EmployeePayslipDistribution $row): string => (string) $row->net_amount));

        if (Dec::cmp($total, (string) $import->net_payable) !== 0) {
            throw new ItalianPayrollException("I netti dei dipendenti ({$total}) non coincidono con il netto contabilizzato ({$import->net_payable}).");
        }

        $employees = Employee::query()->whereIn('id', $distributions->pluck('employee_id'))->get()->keyBy('id');
        $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $distributions->pluck('employee_id'))->get()->keyBy('employee_id');
        $payments = [];
        $missing = [];

        foreach ($distributions as $row) {
            $employee = $employees->get($row->employee_id);
            $iban = $profiles->get($row->employee_id)?->iban;

            if (! is_string($iban) || $iban === '') {
                $missing[] = $employee !== null ? (string) $employee->full_name : (string) $row->employee_id;

                continue;
            }

            $payments[] = [
                'name' => $employee !== null ? (string) $employee->full_name : 'Dipendente',
                'iban' => $iban,
                'amount' => (string) $row->net_amount,
                'end_to_end_id' => substr(sprintf('SAL%04d%02d%s', $period->year, $period->month, str_replace('-', '', (string) $row->employee_id)), 0, 35),
                'remittance' => sprintf('Stipendio %02d/%04d', $period->month, $period->year),
            ];
        }

        if ($missing !== []) {
            throw new ItalianPayrollException('IBAN mancante per: '.implode(', ', $missing).'.');
        }

        $xml = $this->generator->generate(sprintf('SAL-%04d%02d-%s', $period->year, $period->month, substr((string) $period->id, 0, 8)), $command->executionDate, $command->debtor, $payments);

        return new ExportFile(sprintf('SALARI_%04d%02d.xml', $period->year, $period->month), 'application/xml', $xml);
    }
}
