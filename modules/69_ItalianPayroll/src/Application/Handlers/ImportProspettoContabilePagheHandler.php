<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\ItalianPayroll\Application\Commands\ImportProspettoContabilePagheCommand;
use Modules\ItalianPayroll\Domain\Enums\PeriodStatus;
use Modules\ItalianPayroll\Domain\Exceptions\ItalianPayrollException;
use Modules\ItalianPayroll\Infrastructure\Models\EmployeePayslipDistribution;
use Modules\ItalianPayroll\Infrastructure\Models\GlAccountSetting;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\PayrollAccountingImport;
use Modules\ItalianPayroll\Infrastructure\Services\PayrollJournalBuilder;
use Modules\Payroll\Calculation\Dec;

/**
 * Verifies the accounting summary of the consultant (the equations must balance, the employee nets must add up to the net
 * payable) and posts it as one double-entry journal entry dated the last day of the month. A month is posted once.
 */
final class ImportProspettoContabilePagheHandler
{
    public function __construct(
        private readonly PayrollJournalBuilder $journal,
        private readonly DoubleEntryPostingServiceInterface $posting,
    ) {}

    public function handle(ImportProspettoContabilePagheCommand $command): PayrollAccountingImport
    {
        return DB::transaction(function () use ($command): PayrollAccountingImport {
            $period = ItalianPayrollPeriod::query()->lockForUpdate()->findOrFail($command->periodId);

            if ($period->status->rank() < PeriodStatus::ExportedToCdl->rank() || $period->status === PeriodStatus::Paid) {
                throw new ItalianPayrollException('Il prospetto contabile si importa dopo l\'esportazione al consulente e prima del pagamento.');
            }

            if (PayrollAccountingImport::query()->where('payroll_period_id', $period->id)->exists()) {
                throw new ItalianPayrollException("Il prospetto contabile del periodo {$period->month}/{$period->year} è già stato registrato.");
            }

            $summary = $command->summary;
            $netSum = Dec::sum(array_column($command->employeeNets, 'net'));

            if ($command->employeeNets !== [] && Dec::cmp($netSum, $summary['net']) !== 0) {
                throw new ItalianPayrollException("La somma dei netti dei dipendenti ({$netSum}) non coincide con il netto da pagare ({$summary['net']}).");
            }

            $accounts = GlAccountSetting::query()->pluck('account_id', 'role')->map(fn ($id): string => (string) $id)->all();
            $lines = $this->journal->lines($summary, $accounts);
            $postingDate = CarbonImmutable::create($period->year, $period->month, 1)->endOfMonth()->toDateString();
            $fiscalPeriod = FiscalPeriod::query()->where('start_date', '<=', $postingDate)->where('end_date', '>=', $postingDate)->where('status', 'open')->first();

            if ($fiscalPeriod === null) {
                throw new ItalianPayrollException("Nessun periodo contabile aperto per il {$postingDate}.");
            }

            $entry = $this->posting->createAndPost([
                'tenant_id' => (string) $period->tenant_id,
                'company_id' => $command->companyId ?? $period->legal_entity_id,
                'fiscal_period_id' => (string) $fiscalPeriod->id,
                'posting_date' => $postingDate,
                'description' => sprintf('Paghe %02d/%04d', $period->month, $period->year),
                'source_document_type' => 'ipp_payroll_period',
                'source_document_id' => (string) $period->id,
                'lines' => $lines,
            ], $command->actorId);

            $import = PayrollAccountingImport::query()->create([
                'payroll_period_id' => $period->id,
                'total_gross_wages' => $summary['gross'],
                'inps_employee_withholding' => $summary['inps_employee'],
                'inps_employer_contribution' => $summary['inps_employer'],
                'irpef_withholding' => $summary['irpef'],
                'regional_municipal_addizionali' => $summary['addizionali'],
                'tfr_monthly_accrual' => $summary['tfr_accrual'],
                'tfr_fondo_tesoreria_or_previdenza' => $summary['tfr_fund'],
                'inail_accrual' => $summary['inail'] ?? '0.00',
                'net_payable' => $summary['net'],
                'gl_journal_entry_id' => (string) $entry->id,
            ]);

            foreach ($command->employeeNets as $row) {
                EmployeePayslipDistribution::query()->updateOrCreate(
                    ['payroll_period_id' => $period->id, 'employee_id' => $row['employee_id']],
                    ['net_amount' => $row['net']],
                );
            }

            return $import;
        });
    }
}
