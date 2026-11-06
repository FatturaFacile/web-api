<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Withholding;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Exports\CsvBuilder;
use Modules\Payroll\Exports\GeneratedFile;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollWithholdingStatement;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\SwissPayrollSettings;
use Modules\Payroll\Services\LegalParameterResolver;

/**
 * The data of a withholding tax statement for a canton, by month, quarter or year, from the closed runs. The transmission
 * channels of the canton are the application IFonte (manual entry) and ELM-QST from a certified payroll: no public file
 * format for an upload to IFonte was found, so this is the data layer and the CSV is a work file, not a filing. A period
 * once closed is kept as declared; a later change of its data shows up as a correction in the next statement, reversing
 * the old values and adding the new ones (Nota ELM-QST v. 5.0, cap. 6.1).
 */
final class WithholdingStatementService
{
    public function __construct(private readonly LegalParameterResolver $parameters) {}

    /**
     * @return array<string, mixed>
     */
    public function statement(int $year, string $periodType, int $period, ?string $canton): array
    {
        $months = self::months($periodType, $period);
        $payslips = $this->payslips($year, $months);
        $cantons = $canton === null ? $payslips->pluck('withholding_tax_canton')->filter()->unique()->sort()->values() : collect([$canton]);
        $parameters = $this->parameters->resolve('withholding_tax_statement', $year);
        $debtorNumbers = SwissPayrollSettings::query()->first()?->withholding_debtor_numbers ?? [];

        return [
            'year' => $year,
            'period_type' => $periodType,
            'period' => $period,
            'format_status' => 'unverified: no official IFonte upload format is public; data for manual entry or a certified ELM-QST channel',
            'statements' => $cantons->map(function (string $code) use ($payslips, $year, $periodType, $period, $months, $parameters, $debtorNumbers): array {
                $lines = $this->lines($payslips->where('withholding_tax_canton', $code));
                $total = Dec::round(Dec::sum(array_column($lines, 'tax')));

                return [
                    'canton' => $code,
                    'debtor_number' => $debtorNumbers[$code] ?? null,
                    'recommended_period' => $parameters->optional('closing_period_by_canton', [])[$code] ?? PayrollWithholdingStatement::Monthly,
                    'lines' => $lines,
                    'corrections' => $this->corrections($code, $year, $months),
                    'total_tax' => $total,
                    'collection_commission_electronic' => Dec::round(Dec::div(Dec::mul($total, (string) ($parameters->optional('collection_commission_percent', [])['electronic'] ?? '0')), '100')),
                    'is_closed' => PayrollWithholdingStatement::query()->where(['canton' => $code, 'year' => $year, 'period_type' => $periodType, 'period' => $period])->exists(),
                    'missing_data' => array_values(array_filter(array_map(static fn (array $line): ?array => $line['missing'] === [] ? null : ['employee_id' => $line['employee_id'], 'fields' => $line['missing']], $lines))),
                ];
            })->values()->all(),
            'warnings' => $parameters->warnings,
        ];
    }

    /**
     * Close a period of a canton: what it holds now is what was declared.
     */
    public function close(User $actor, int $year, string $periodType, int $period, string $canton): PayrollWithholdingStatement
    {
        $statement = $this->statement($year, $periodType, $period, $canton)['statements'][0];

        if ($statement['is_closed']) {
            throw new PayrollException('Periodo già chiuso: le variazioni entrano come correzioni nel conteggio successivo.');
        }

        if ($statement['lines'] === []) {
            throw new PayrollException("Nessuna busta paga con imposta alla fonte per il cantone {$canton} nel periodo.");
        }

        if ($statement['missing_data'] !== []) {
            throw new PayrollException('Dati mancanti: completare i dipendenti indicati nel conteggio prima di chiuderlo.');
        }

        $content = ['lines' => $statement['lines'], 'corrections' => $statement['corrections']];

        return PayrollWithholdingStatement::query()->create([
            'canton' => $canton,
            'year' => $year,
            'period_type' => $periodType,
            'period' => $period,
            'content' => $content,
            'content_hash' => hash('sha256', (string) json_encode($content, JSON_THROW_ON_ERROR)),
            'total_tax' => $statement['total_tax'],
            'closed_by' => $actor->id,
            'closed_at' => CarbonImmutable::now(),
        ]);
    }

    public function file(int $year, string $periodType, int $period, string $canton): GeneratedFile
    {
        $statement = $this->statement($year, $periodType, $period, $canton)['statements'][0];
        $csv = new CsvBuilder;
        $csv->row('Tipo', 'Mese', 'N. AVS', 'Cognome', 'Nome', 'Data di nascita', 'Entrata', 'Uscita', 'Cantone di lavoro', 'Stato di domicilio', 'Regime frontaliere', 'Validità dal', 'Codice tariffa', 'Salario lordo', 'Salario IF', 'Aliquota %', 'Imposta');

        foreach ([...$statement['lines'], ...$statement['corrections']] as $line) {
            $csv->row($line['entry_type'], $line['month'], $line['social_security_number'], $line['last_name'], $line['first_name'], $line['birth_date'], $line['entry_date'], $line['exit_date'], $line['work_canton'], $line['residence_country'], $line['frontier_regime'], $line['frontier_status_valid_from'], $line['tariff_code'], $line['gross'], $line['basis'], $line['rate_percent'], $line['tax']);
        }

        return new GeneratedFile(
            sprintf('Conteggio_IF_%s_%d_%s_%d_dati_formato_non_ufficiale.csv', $canton, $year, $periodType, $period),
            $csv->encoded(),
            'Dati per la compilazione del conteggio. Tracciato di questa applicazione: nessun formato di caricamento IFonte è pubblico, quindi non è un file ufficiale.',
        );
    }

    /**
     * @return list<int>
     */
    public static function months(string $periodType, int $period): array
    {
        return match ($periodType) {
            PayrollWithholdingStatement::Monthly => [$period],
            PayrollWithholdingStatement::Quarterly => range(($period - 1) * 3 + 1, $period * 3),
            default => range(1, 12),
        };
    }

    /**
     * @param  list<int>  $months
     * @return Collection<int, Payslip>
     */
    private function payslips(int $year, array $months): Collection
    {
        $runs = PayrollRun::query()->where('year', $year)->whereIn('month', $months)->where('status', PayrollRun::StatusFinal)->pluck('id');

        return Payslip::query()->with('lines')->whereIn('payroll_run_id', $runs)->whereNotNull('withholding_tax_canton')->orderBy('employee_name')->orderBy('month')->get();
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @return list<array<string, mixed>>
     */
    private function lines(Collection $payslips): array
    {
        $employees = Employee::query()->withTrashed()->whereIn('id', $payslips->pluck('employee_id'))->get()->keyBy('id');
        $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $payslips->pluck('employee_id'))->get()->keyBy('employee_id');

        return $payslips->map(static function (Payslip $payslip) use ($employees, $profiles): array {
            $employee = $employees->get($payslip->employee_id);
            $profile = $profiles->get($payslip->employee_id);
            $amounts = WithholdingTaxAmounts::of(collect([$payslip]));
            $number = $profile?->social_security_number ?? $employee?->social_security_number;

            return [
                'entry_type' => 'current',
                'employee_id' => (string) $payslip->employee_id,
                'month' => $payslip->month,
                'social_security_number' => $number,
                'last_name' => $employee?->last_name,
                'first_name' => $employee?->first_name,
                'birth_date' => $employee?->birth_date?->format('Y-m-d'),
                'entry_date' => $employee?->hire_date?->format('Y-m-d'),
                'exit_date' => $employee?->termination_date?->format('Y-m-d'),
                'work_canton' => $profile?->work_canton,
                'residence_country' => $profile?->residence_country,
                'residence_canton' => $profile?->residence_canton,
                'frontier_regime' => $profile?->frontier_regime,
                'frontier_status_valid_from' => $profile?->frontier_status_valid_from?->format('Y-m-d'),
                'tariff_code' => $payslip->withholding_tax_code,
                'gross' => $amounts['gross'],
                'basis' => $amounts['basis'],
                'rate_percent' => (string) $payslip->withholding_tax_rate_percent,
                'tax' => $amounts['tax'],
                'missing' => array_values(array_filter([
                    $number === null ? 'social_security_number' : null,
                    $employee?->birth_date === null ? 'birth_date' : null,
                    $payslip->withholding_tax_code === null ? 'tariff_code' : null,
                ])),
            ];
        })->values()->all();
    }

    /**
     * The months closed earlier in the year whose data changed since: the old line reversed, the new one added.
     *
     * @param  list<int>  $currentMonths
     * @return list<array<string, mixed>>
     */
    private function corrections(string $canton, int $year, array $currentMonths): array
    {
        $closed = PayrollWithholdingStatement::query()->where('canton', $canton)->where('year', $year)->orderBy('closed_at')->get();
        $declared = [];

        foreach ($closed as $statement) {
            foreach ([...$statement->content['lines'], ...$statement->content['corrections']] as $line) {
                if (in_array($line['month'], $currentMonths, true) && $line['entry_type'] === 'current') {
                    continue;
                }

                if ($line['entry_type'] !== 'reversal') {
                    $declared["{$line['employee_id']}|{$line['month']}"] = $line;
                }
            }
        }

        if ($declared === []) {
            return [];
        }

        $months = array_values(array_unique(array_column($declared, 'month')));
        $current = collect($this->lines($this->payslips($year, $months)->where('withholding_tax_canton', $canton)))
            ->keyBy(static fn (array $line): string => "{$line['employee_id']}|{$line['month']}");
        $corrections = [];

        foreach ($declared as $key => $line) {
            $now = $current->get($key);

            if ($now !== null && self::isSameDeclaration($line, $now)) {
                continue;
            }

            $corrections[] = [
                ...$line,
                'entry_type' => 'reversal',
                'gross' => Dec::round(Dec::neg($line['gross'])),
                'basis' => Dec::round(Dec::neg($line['basis'])),
                'tax' => Dec::round(Dec::neg($line['tax'])),
            ];

            if ($now !== null) {
                $corrections[] = [...$now, 'entry_type' => 'correction'];
            }
        }

        return $corrections;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private static function isSameDeclaration(array $left, array $right): bool
    {
        return $left['tax'] === $right['tax'] && $left['basis'] === $right['basis'] && $left['tariff_code'] === $right['tariff_code'];
    }
}
