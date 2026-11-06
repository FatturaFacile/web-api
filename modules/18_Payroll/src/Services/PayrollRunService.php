<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\PayrollResult;
use Modules\Payroll\Events\PayrollRunFinalizedEvent;
use Modules\Payroll\Events\PayrollRunReopenedEvent;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Exceptions\PayrollRunClosedException;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollRunEvent;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

/**
 * The life of a monthly run: calculated and recalculated while it is a draft, closed, reopened explicitly.
 */
final class PayrollRunService
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * Calculates (or recalculates) the run of a month.
     */
    public function calculate(Tenant $tenant, User $actor, int $year, int $month, ?string $paymentDate): PayrollRun
    {
        return DB::transaction(function () use ($tenant, $actor, $year, $month, $paymentDate): PayrollRun {
            $run = $this->prepareRun($tenant, $year, $month, $paymentDate);
            $results = $this->calculator->calculate($tenant, $year, $month);

            foreach ($results as $result) {
                $this->store($run, $result);
            }

            $run->calculated_at = now();
            $run->save();
            $this->history($run, PayrollRunEvent::Calculated, $actor, null);

            return $run->load('payslips');
        });
    }

    /**
     * Closes a run: from now on it is immutable, and accounting learns about it through the domain event.
     */
    public function finalize(PayrollRun $run, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->isFinal()) {
                throw new PayrollRunClosedException("L'elaborazione è già chiusa.");
            }

            if (! $locked->payslips()->exists()) {
                throw new PayrollException("Calcolare l'elaborazione prima di chiuderla.");
            }

            $locked->status = PayrollRun::StatusFinal;
            $locked->finalized_at = now();
            $locked->finalized_by = $actor->id;
            $locked->save();

            $totals = $this->totals($locked);
            $this->history($locked, PayrollRunEvent::Finalized, $actor, null, $totals);

            event(new PayrollRunFinalizedEvent(
                payrollRunId: $locked->id,
                year: $locked->year,
                month: $locked->month,
                paymentDate: $locked->payment_date?->format('Y-m-d'),
                currency: $locked->currency,
                totals: $totals,
                amountsByCode: $this->amountsByCode($locked),
                tenantId: $locked->tenant_id,
            ));

            return $locked;
        });
    }

    /**
     * Reopens the latest run. The reason is mandatory and stays in the history.
     */
    public function reopen(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor, $reason): PayrollRun {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if (! $locked->isFinal()) {
                throw new PayrollException("L'elaborazione non è chiusa.");
            }

            $periodIndex = ($locked->year * 12) + $locked->month;

            if (PayrollRun::query()->whereRaw('(year * 12) + month > ?', [$periodIndex])->exists()) {
                throw new PayrollException("Esistono elaborazioni successive: si può riaprire solo l'ultima elaborazione.");
            }

            $this->withReopenGuardLifted(fn () => $locked->markReopened((string) $actor->id, $reason));
            $this->history($locked, PayrollRunEvent::Reopened, $actor, $reason, $this->totals($locked));

            event(new PayrollRunReopenedEvent($locked->id, $locked->year, $locked->month, $reason, $locked->tenant_id));

            return $locked;
        });
    }

    private function prepareRun(Tenant $tenant, int $year, int $month, ?string $paymentDate): PayrollRun
    {
        $run = PayrollRun::query()->where('year', $year)->where('month', $month)->lockForUpdate()->first();

        if ($run === null) {
            return PayrollRun::query()->create([
                'year' => $year,
                'month' => $month,
                'country' => strtoupper((string) $tenant->country),
                'currency' => $tenant->default_currency ?? (strtoupper((string) $tenant->country) === 'CH' ? 'CHF' : 'EUR'),
                'status' => PayrollRun::StatusDraft,
                'payment_date' => $paymentDate,
            ]);
        }

        if ($run->isFinal()) {
            throw PayrollRunClosedException::forPeriod($year, $month);
        }

        $run->payment_date = $paymentDate ?? $run->payment_date;
        $payslipIds = Payslip::query()->where('payroll_run_id', $run->id)->pluck('id');
        PayslipLine::query()->whereIn('payslip_id', $payslipIds)->delete();
        Payslip::query()->whereIn('id', $payslipIds)->delete();

        return $run;
    }

    private function store(PayrollRun $run, PayrollResult $result): void
    {
        $data = $result->payslip;
        $payslip = Payslip::query()->create([
            'payroll_run_id' => $run->id,
            'employee_id' => $data->employeeId,
            'year' => $data->year,
            'month' => $data->month,
            'employee_name' => $data->employeeName,
            'paid_days' => $data->paidDays,
            'gross_salary' => $data->grossSalary,
            'ahv_basis' => $data->ahvBasis,
            'alv_basis' => $data->alvBasis,
            'uvg_basis' => $data->uvgBasis,
            'ktg_basis' => $data->ktgBasis,
            'bvg_insured_salary' => $data->bvgInsuredSalary,
            'withholding_tax_basis' => $data->withholdingTaxBasis,
            'withholding_tax_rate_percent' => $data->withholdingTaxRatePercent,
            'withholding_tax_code' => $data->withholdingTaxCode,
            'withholding_tax_canton' => $data->withholdingTaxCanton,
            'employee_deductions' => $data->employeeDeductions,
            'net_salary' => $data->netSalary,
            'payout_amount' => $data->payoutAmount,
            'employer_contributions' => $data->employerContributions,
            'warnings' => $data->warnings,
        ]);

        $now = now();
        $rows = [];

        foreach ($result->lines as $line) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $payslip->tenant_id,
                'payslip_id' => $payslip->id,
                'wage_type_code' => $line->wageTypeCode,
                'description' => mb_substr($line->description, 0, 255),
                'kind' => $line->kind,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'amount' => $line->amount,
                'certificate_box' => $line->certificateBox,
                'sort_order' => $line->sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        PayslipLine::query()->insert($rows);
    }

    /**
     * @param  callable(): void  $reopen
     */
    private function withReopenGuardLifted(callable $reopen): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $reopen();

            return;
        }

        DB::statement("SET LOCAL app.payroll_reopen = 'on'");
        $reopen();
        DB::statement("SET LOCAL app.payroll_reopen = 'off'");
    }

    /**
     * @param  array<string, string>|null  $totals
     */
    private function history(PayrollRun $run, string $action, User $actor, ?string $reason, ?array $totals = null): void
    {
        PayrollRunEvent::query()->create([
            'payroll_run_id' => $run->id,
            'action' => $action,
            'reason' => $reason,
            'totals' => $totals ?? $this->totals($run),
            'actor_id' => $actor->id,
            'actor_name' => (string) $actor->name,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function totals(PayrollRun $run): array
    {
        $payslips = Payslip::query()->where('payroll_run_id', $run->id)->get();
        $totals = [];

        foreach (['gross_salary', 'employee_deductions', 'net_salary', 'payout_amount', 'employer_contributions'] as $column) {
            $totals[$column] = Dec::round(Dec::sum($payslips->map(static fn (Payslip $payslip): string => (string) $payslip->{$column})));
        }

        return $totals;
    }

    /**
     * @return array<string, string>
     */
    private function amountsByCode(PayrollRun $run): array
    {
        $lines = PayslipLine::query()->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $run->id)->pluck('id'))->get();
        $amounts = [];

        foreach ($lines as $line) {
            $amounts[$line->wage_type_code] = Dec::round(Dec::add($amounts[$line->wage_type_code] ?? '0', (string) $line->amount));
        }

        ksort($amounts);

        return $amounts;
    }
}
