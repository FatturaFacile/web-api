<?php

declare(strict_types=1);

namespace Modules\Payroll\Services;

use App\Models\Tenant;
use App\Models\User;
use Enterprise\EventBus\Services\EventDispatcher;
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
 * The life of a monthly run: Draft → Calculated (recalculated at will) → Approved → Final (immutable, posted to the
 * General Ledger through the domain event). Reopening an approved run revokes the approval; reopening a closed run
 * brings it back to Draft. Every step is in the append-only history with its actor, and the reasons of reopenings.
 */
final class PayrollRunService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly EventDispatcher $dispatcher,
    ) {}

    /**
     * Calculates (or recalculates) the run of a month. An approved run has to be reopened first, so an approval always
     * refers to the payslips it saw.
     */
    public function calculate(Tenant $tenant, User $actor, int $year, int $month, ?string $paymentDate): PayrollRun
    {
        return DB::transaction(function () use ($tenant, $actor, $year, $month, $paymentDate): PayrollRun {
            $run = $this->prepareRun($tenant, $year, $month, $paymentDate);
            $results = $this->calculator->calculate($tenant, $year, $month);

            foreach ($results as $result) {
                $this->store($run, $result);
            }

            $run->status = PayrollRun::StatusCalculated;
            $run->calculated_at = now();
            $run->save();
            $this->history($run, PayrollRunEvent::Calculated, $actor, null);

            return $run->load('payslips');
        });
    }

    /**
     * Approves a calculated run: the payslips are frozen for closing until the approval is revoked.
     */
    public function approve(PayrollRun $run, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->isFinal()) {
                throw new PayrollRunClosedException("L'elaborazione è già chiusa.");
            }

            if ($locked->status === PayrollRun::StatusApproved) {
                throw new PayrollRunClosedException("L'elaborazione è già approvata.");
            }

            if ($locked->calculated_at === null || ! $locked->payslips()->exists()) {
                throw new PayrollException("Calcolare l'elaborazione prima di approvarla.");
            }

            $locked->forceFill(['status' => PayrollRun::StatusApproved, 'approved_at' => now(), 'approved_by' => $actor->id])->save();
            $this->history($locked, PayrollRunEvent::Approved, $actor, null);

            return $locked;
        });
    }

    /**
     * Closes an approved run: from now on it is immutable, and accounting learns about it through the domain event.
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

            if ($locked->status !== PayrollRun::StatusApproved) {
                throw new PayrollException("Approvare l'elaborazione prima di chiuderla.");
            }

            $locked->status = PayrollRun::StatusFinal;
            $locked->finalized_at = now();
            $locked->finalized_by = $actor->id;
            $locked->save();

            $totals = $this->totals($locked);
            $this->history($locked, PayrollRunEvent::Finalized, $actor, null, $totals);

            $this->dispatcher->publishAndDispatch(new PayrollRunFinalizedEvent(
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
     * Reopens a run with a mandatory reason that stays in the history: an approved run goes back to Calculated (the
     * approval is revoked), the latest closed run goes back to Draft and accounting reverses its posting.
     */
    public function reopen(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor, $reason): PayrollRun {
            $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status === PayrollRun::StatusApproved) {
                $locked->forceFill(['status' => PayrollRun::StatusCalculated, 'approved_at' => null, 'approved_by' => null])->save();
                $this->history($locked, PayrollRunEvent::ApprovalRevoked, $actor, $reason);

                return $locked;
            }

            if (! $locked->isFinal()) {
                throw new PayrollException("L'elaborazione non è chiusa né approvata.");
            }

            $periodIndex = ($locked->year * 12) + $locked->month;

            if (PayrollRun::query()->whereRaw('(year * 12) + month > ?', [$periodIndex])->exists()) {
                throw new PayrollException("Esistono elaborazioni successive: si può riaprire solo l'ultima elaborazione.");
            }

            $this->withReopenGuardLifted(fn () => $locked->markReopened((string) $actor->id, $reason));
            $this->history($locked, PayrollRunEvent::Reopened, $actor, $reason, $this->totals($locked));

            $this->dispatcher->publishAndDispatch(new PayrollRunReopenedEvent($locked->id, $locked->year, $locked->month, $reason, $locked->tenant_id));

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

        if ($run->status === PayrollRun::StatusApproved) {
            throw new PayrollRunClosedException("L'elaborazione è approvata: revocare l'approvazione (riapertura con motivo) prima di ricalcolarla.");
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
