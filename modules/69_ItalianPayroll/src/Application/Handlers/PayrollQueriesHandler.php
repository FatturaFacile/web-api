<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Handlers;

use Carbon\CarbonImmutable;
use Modules\ItalianPayroll\Application\Queries\GetEmployeeCedolinoDownloadAuditQuery;
use Modules\ItalianPayroll\Application\Queries\GetF24PayrollTaxSummaryQuery;
use Modules\ItalianPayroll\Application\Queries\GetTfrAccrualLedgerQuery;
use Modules\ItalianPayroll\Infrastructure\Models\EmployeePayslipDistribution;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\PayrollAccountingImport;
use Modules\ItalianPayroll\Infrastructure\Models\PayslipDownloadAudit;
use Modules\ItalianPayroll\Infrastructure\Services\ItalianTfrCalculator;
use Modules\Payroll\Calculation\Dec;

/**
 * The three read models of the module: the TFR accrual ledger of a year, the F24 tax summary of a month and the download
 * audit of the payslips.
 */
final class PayrollQueriesHandler
{
    /** F24 tax codes of the payroll withholdings: IRPEF on employment income, regional and municipal surtax. */
    private const string IrpefCode = '1001';

    private const string RegionalSurtaxCode = '3802';

    private const string MunicipalSurtaxCode = '3848';

    public function __construct(private readonly ItalianTfrCalculator $tfr) {}

    /**
     * Month by month accrual of the year from the imported summaries, with the running fund and, when the FOI change is
     * given, the revaluation of the opening balance (the fund accrued before the year is not kept here: it is passed as
     * zero, so the projection covers the accruals of this year only).
     *
     * @return array{year: int, months: list<array{month: int, accrual: string, running: string}>, total: string, revaluation: array{gross: string, tax: string, net: string, rate: string}|null}
     */
    public function tfrLedger(GetTfrAccrualLedgerQuery $query): array
    {
        $periods = ItalianPayrollPeriod::query()->where('year', $query->year)
            ->when($query->legalEntityId !== null, fn ($q) => $q->where('legal_entity_id', $query->legalEntityId))->orderBy('month')->get();
        $imports = PayrollAccountingImport::query()->whereIn('payroll_period_id', $periods->pluck('id'))->get()->keyBy('payroll_period_id');
        $running = '0.00';
        $months = [];

        foreach ($periods as $period) {
            $accrual = (string) ($imports->get($period->id)?->tfr_monthly_accrual ?? '0.00');
            $running = Dec::add($running, $accrual);
            $months[] = ['month' => $period->month, 'accrual' => Dec::round($accrual), 'running' => Dec::round($running)];
        }

        return [
            'year' => $query->year,
            'months' => $months,
            'total' => Dec::round($running),
            'revaluation' => $query->foiPercent !== null ? $this->tfr->netRevaluation($running, $query->foiPercent) : null,
        ];
    }

    /**
     * What goes into the F24 of the month, due the 16th of the next month.
     *
     * @return array{period_id: string, due_date: string, lines: list<array{section: string, tax_code: string, description: string, amount: string}>, total: string}
     */
    public function f24Summary(GetF24PayrollTaxSummaryQuery $query): array
    {
        $period = ItalianPayrollPeriod::query()->findOrFail($query->periodId);
        $import = PayrollAccountingImport::query()->where('payroll_period_id', $period->id)->firstOrFail();
        $addizionali = (string) $import->regional_municipal_addizionali;
        $regional = Dec::round(Dec::div($addizionali, '2'));
        $lines = [
            ['section' => 'erario', 'tax_code' => self::IrpefCode, 'description' => 'Ritenute IRPEF lavoro dipendente', 'amount' => Dec::round((string) $import->irpef_withholding)],
            ['section' => 'regioni', 'tax_code' => self::RegionalSurtaxCode, 'description' => 'Addizionale regionale', 'amount' => $regional],
            ['section' => 'enti_locali', 'tax_code' => self::MunicipalSurtaxCode, 'description' => 'Addizionale comunale', 'amount' => Dec::sub(Dec::round($addizionali), $regional)],
        ];
        $total = Dec::sum(array_column($lines, 'amount'));

        return [
            'period_id' => (string) $period->id,
            'due_date' => CarbonImmutable::create($period->year, $period->month, 1)->addMonthNoOverflow()->day(16)->toDateString(),
            'lines' => $lines,
            'total' => Dec::round($total),
        ];
    }

    /**
     * @return list<array{employee_id: string, net_amount: string, delivered: bool, first_viewed_at: string|null, first_viewed_from_ip: string|null, events: list<array{kind: string, ip: string|null, biometric: bool, at: string}>}>
     */
    public function downloadAudit(GetEmployeeCedolinoDownloadAuditQuery $query): array
    {
        $distributions = EmployeePayslipDistribution::query()->where('payroll_period_id', $query->periodId)
            ->when($query->employeeId !== null, fn ($q) => $q->where('employee_id', $query->employeeId))->get();
        $audits = PayslipDownloadAudit::query()->whereIn('distribution_id', $distributions->pluck('id'))->orderBy('occurred_at')->get()->groupBy('distribution_id');

        return $distributions->map(fn (EmployeePayslipDistribution $row): array => [
            'employee_id' => (string) $row->employee_id,
            'net_amount' => (string) $row->net_amount,
            'delivered' => $row->cedolino_pdf_document_id !== null,
            'first_viewed_at' => $row->viewed_by_employee_at?->toIso8601String(),
            'first_viewed_from_ip' => $row->viewed_from_ip,
            'events' => ($audits->get($row->id) ?? collect())->map(fn (PayslipDownloadAudit $audit): array => [
                'kind' => $audit->document_kind, 'ip' => $audit->ip_address, 'biometric' => $audit->biometric_confirmed, 'at' => $audit->occurred_at->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();
    }
}
