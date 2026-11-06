<?php

declare(strict_types=1);

namespace Modules\Payroll\Exports;

use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\Italian\ItalianWageCodes;
use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;

/**
 * The CSV exports of the desktop: Swiss annual salary declaration, Italian F24, UniEmens and CU data. None of them is
 * an official telematic file: they are loaded into the software of the funds and of the tax agency.
 */
final class PayrollFilingService
{
    public function salaryDeclaration(int $year): GeneratedFile
    {
        $closedRunIds = PayrollRun::query()->where('year', $year)->where('status', PayrollRun::StatusFinal)->pluck('id');
        $payslips = Payslip::query()->whereIn('payroll_run_id', $closedRunIds)->orderBy('employee_name')->orderBy('month')->get();

        if ($payslips->isEmpty()) {
            throw new PayrollException("Nessuna elaborazione chiusa nell'anno.");
        }

        $employeeIds = $payslips->pluck('employee_id')->unique()->all();
        $employees = Employee::query()->withTrashed()->whereIn('id', $employeeIds)->get()->keyBy('id');
        $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');

        $csv = new CsvBuilder;
        $csv->row('N. AVS', 'Cognome', 'Nome', 'Data di nascita', 'Sesso', 'Dal', 'Al', 'Salario lordo', 'Salario AVS', 'Salario AD', 'Salario LAINF', 'Salario IGM', 'Salario assicurato LPP (media)', 'Imposta alla fonte - salario determinante', 'Cantone di lavoro');

        foreach ($payslips->groupBy('employee_id') as $employeeId => $group) {
            $employee = $employees->get($employeeId);
            $profile = $profiles->get($employeeId);
            $csv->row(
                $profile?->social_security_number ?? $employee?->social_security_number,
                $employee?->last_name,
                $employee?->first_name,
                $employee?->birth_date,
                $profile?->gender ?? $employee?->gender,
                PayrollDates::monthStart($year, (int) $group->min('month')),
                PayrollDates::monthEnd($year, (int) $group->max('month')),
                self::total($group, 'gross_salary'),
                self::total($group, 'ahv_basis'),
                self::total($group, 'alv_basis'),
                self::total($group, 'uvg_basis'),
                self::total($group, 'ktg_basis'),
                Dec::round(Dec::div(self::total($group, 'bvg_insured_salary'), (string) $group->count())),
                self::total($group, 'withholding_tax_basis'),
                $profile?->work_canton,
            );
        }

        $csv->row('Totale', '', '', '', '', '', '', self::total($payslips, 'gross_salary'), self::total($payslips, 'ahv_basis'), self::total($payslips, 'alv_basis'), self::total($payslips, 'uvg_basis'), self::total($payslips, 'ktg_basis'), '', self::total($payslips, 'withholding_tax_basis'), '');

        return new GeneratedFile(
            "Dichiarazione_salari_{$year}.csv",
            $csv->encoded(),
            'Dichiarazione dei salari per cassa AVS, LAINF, IGM e LPP. Senza un distributore Swissdec certificato va inviata con i portali delle casse o allegata ai loro moduli.',
        );
    }

    public function payrollF24(int $year, int $month): GeneratedFile
    {
        $data = ItalianPayrollPeriodData::load($year, $month);
        $period = sprintf('%02d%d', $month, $year);
        $due = PayrollDates::addMonths(PayrollDates::date(sprintf('%04d-%02d-16', $year, $month)), 1);
        $installment = sprintf('%02d11', $month);
        $irpef = $data->deducted(ItalianWageCodes::Irpef);

        $csv = new CsvBuilder;
        $csv->row('Sezione', 'Codice tributo / causale', 'Codice ente / sede / regione', 'Matricola / rateazione', 'Periodo / anno di riferimento', 'Importo a debito', 'Importo a credito');
        $csv->row('Erario', '1001', '', '', $year, $irpef, '0');

        $this->surchargeRows($csv, $data, ItalianWageCodes::RegionalSurcharge, 'Regioni', '3802', 'region_code', $installment, $year - 1);
        $this->surchargeRows($csv, $data, ItalianWageCodes::MunicipalSurcharge, 'IMU e altri tributi locali', '3848', 'municipality_code', $installment, $year - 1);

        $inps = Dec::add($data->deducted(ItalianWageCodes::InpsEmployee), $data->added(ItalianWageCodes::InpsEmployer));
        $csv->row('INPS', 'DM10', $data->settings->inps_office_code ?? '', $data->settings->inps_company_number ?? '', $period, Dec::round($inps), '0');
        $wedge = $data->added(ItalianWageCodes::TaxWedge);

        return new GeneratedFile(
            sprintf('F24_paghe_%d-%02d.csv', $year, $month),
            $csv->encoded(),
            'F24 da versare entro il '.$due->format('d.m.Y').': IRPEF '.Dec::round($irpef).', INPS '.Dec::round($inps).'. Somme cuneo fiscale anticipate ai dipendenti '.Dec::round($wedge).": recuperarle in compensazione con il codice tributo indicato dall'Agenzia delle Entrate.",
        );
    }

    public function uniEmensData(int $year, int $month): GeneratedFile
    {
        $data = ItalianPayrollPeriodData::load($year, $month);
        $csv = new CsvBuilder;
        $csv->row('Matricola azienda', 'Periodo', 'Codice fiscale', 'Cognome', 'Nome', 'Qualifica', 'CCNL', 'Livello', 'Imponibile', 'Contributo dipendente', 'Contributo azienda', 'Giorni retribuiti', 'Settimane utili', 'Data assunzione', 'Data cessazione');

        foreach ($data->payslips as $payslip) {
            $employee = $data->employee($payslip->employee_id);
            $profile = $data->profile($payslip->employee_id);
            $csv->row(
                $data->settings?->inps_company_number,
                sprintf('%d-%02d', $year, $month),
                $data->taxCode($payslip->employee_id),
                $employee?->last_name,
                $employee?->first_name,
                $profile?->inps_qualification,
                $profile?->collective_agreement_code,
                $profile?->contract_level,
                $payslip->ahv_basis,
                $data->deducted(ItalianWageCodes::InpsEmployee, $payslip->employee_id),
                $data->added(ItalianWageCodes::InpsEmployer, $payslip->employee_id),
                Dec::of((string) $payslip->paid_days),
                Dec::ceil(Dec::div((string) $payslip->paid_days, '6')),
                $employee?->hire_date,
                $employee?->termination_date,
            );
        }

        return new GeneratedFile(
            sprintf('UniEmens_dati_%d-%02d.csv', $year, $month),
            $csv->encoded(),
            "Dati per le denunce individuali UniEmens: caricarli nel software di invio (o nel gestionale del consulente) e controllarli prima della trasmissione all'INPS.",
        );
    }

    public function cuData(int $year): GeneratedFile
    {
        $data = ItalianPayrollPeriodData::load($year);
        $csv = new CsvBuilder;
        $csv->row('Codice fiscale', 'Cognome', 'Nome', 'Periodo dal', 'Periodo al', 'Punto 1 - Redditi di lavoro dipendente', 'Punto 21 - Ritenute IRPEF', 'Punto 22 - Addizionale regionale', 'Addizionale comunale (saldo anno precedente)', 'Somme cuneo fiscale non imponibili', 'Contributi previdenziali a carico del lavoratore', 'TFR maturato');

        foreach ($data->payslipsByEmployee() as $employeeId => $group) {
            $employeeId = (string) $employeeId;
            $employee = $data->employee($employeeId);
            $csv->row(
                $data->taxCode($employeeId),
                $employee?->last_name,
                $employee?->first_name,
                PayrollDates::monthStart($year, (int) $group->min('month')),
                PayrollDates::monthEnd($year, (int) $group->max('month')),
                self::total($group, 'withholding_tax_basis'),
                $data->deducted(ItalianWageCodes::Irpef, $employeeId),
                $data->deducted(ItalianWageCodes::RegionalSurcharge, $employeeId),
                $data->deducted(ItalianWageCodes::MunicipalSurcharge, $employeeId),
                $data->added(ItalianWageCodes::TaxWedge, $employeeId),
                $data->deducted(ItalianWageCodes::InpsEmployee, $employeeId),
                $data->added(ItalianWageCodes::Tfr, $employeeId),
            );
        }

        return new GeneratedFile(
            "CU_dati_{$year}.csv",
            $csv->encoded(),
            "Dati riepilogativi per la Certificazione Unica: riportarli nel software di compilazione e invio della CU (tracciato telematico dell'Agenzia delle Entrate) e verificarli con il controllo ufficiale.",
        );
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     */
    private static function total(Collection $payslips, string $column): string
    {
        return Dec::round(Dec::sum($payslips->map(static fn (Payslip $payslip): string => (string) $payslip->{$column})));
    }

    private function surchargeRows(CsvBuilder $csv, ItalianPayrollPeriodData $data, string $code, string $section, string $taxCode, string $profileColumn, string $installment, int $referenceYear): void
    {
        $groups = $data->payslips->groupBy(static fn (Payslip $payslip): string => (string) $data->profile($payslip->employee_id)?->{$profileColumn});

        foreach ($groups as $entity => $group) {
            $amount = Dec::sum($group->map(static fn (Payslip $payslip): string => $data->deducted($code, $payslip->employee_id)));

            if (Dec::isPositive($amount)) {
                $csv->row($section, $taxCode, (string) $entity, $installment, $referenceYear, Dec::round($amount), '0');
            }
        }
    }
}
