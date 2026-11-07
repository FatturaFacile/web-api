<?php

declare(strict_types=1);

namespace Modules\Payroll\Services\Extras;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollPaymentBatch;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;

/**
 * Pays a closed run through Treasury: one batch for each debtor account, so that salaries can leave several banks. The
 * batches then follow the ordinary Treasury path of approval by a second person, frozen pain.001 export and bank outcome.
 * The amounts come from the payslips, the IBANs from the encrypted payroll profile.
 */
final class PayrollPaymentFileService
{
    /** A batch in these states is dead: its salaries can be paid by a new one. */
    private const array DeadStatuses = ['rejected', 'cancelled'];

    public function __construct(private readonly PaymentFactoryServiceInterface $payments) {}

    /**
     * @param  list<array{bank_account_id: string, employee_ids?: list<string>|null, department_id?: string|null}>  $allocations  The first match wins; an allocation with neither is the default.
     * @return Collection<int, PaymentBatch>
     */
    public function createBatches(PayrollRun $run, User $actor, string $currency, array $allocations): Collection
    {
        if (! $run->isFinal()) {
            throw new PayrollException("Gli stipendi si pagano quando l'elaborazione è chiusa.");
        }

        $this->assertNotPaidAlready($run);

        $payslips = Payslip::query()->where('payroll_run_id', $run->id)->where('payout_amount', '>', 0)->orderBy('employee_name')->get();
        $profiles = PayrollEmployeeProfile::query()->whereIn('employee_id', $payslips->pluck('employee_id'))->get()->keyBy('employee_id');
        $employees = Employee::query()->withTrashed()->whereIn('id', $payslips->pluck('employee_id'))->get()->keyBy('id');
        $byAccount = [];
        $problems = [];

        foreach ($payslips as $payslip) {
            $iban = $profiles->get($payslip->employee_id)?->iban;
            $accountId = self::accountFor($employees->get($payslip->employee_id), $allocations);

            if ($iban === null || $iban === '') {
                $problems[] = "IBAN mancante per {$payslip->employee_name}.";
            } elseif ($accountId === null) {
                $problems[] = "Nessun conto di addebito per {$payslip->employee_name}.";
            } else {
                $byAccount[$accountId][] = $this->line($payslip, $iban, $run);
            }
        }

        if ($problems !== []) {
            throw new PayrollException(implode(' ', $problems));
        }

        if ($byAccount === []) {
            throw new PayrollException('Nessuno stipendio da pagare in questa elaborazione.');
        }

        return DB::transaction(fn (): Collection => collect($byAccount)->map(fn (array $lines, string $accountId): PaymentBatch => $this->batch($run, $actor, $currency, $accountId, $lines))->values());
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function batch(PayrollRun $run, User $actor, string $currency, string $accountId, array $lines): PaymentBatch
    {
        $account = BankAccount::query()->findOrFail($accountId);

        if ($account->currency !== null && $account->currency !== $currency) {
            throw new PayrollException("Il conto {$account->iban} è in {$account->currency}, gli stipendi in {$currency}.");
        }

        $batch = $this->payments->createBatch([
            'tenant_id' => $run->tenant_id,
            'bank_account_id' => $account->id,
            'batch_reference' => sprintf('SAL-%d-%02d-%s', $run->year, $run->month, strtoupper(substr((string) $account->id, -6))),
            'payment_method' => 'sepa_credit_transfer',
            'execution_date' => $run->payment_date?->format('Y-m-d'),
            'currency' => $currency,
        ], $lines, (string) $actor->id);

        PayrollPaymentBatch::query()->create(['payroll_run_id' => $run->id, 'payment_batch_id' => $batch->id]);

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Payslip $payslip, string $iban, PayrollRun $run): array
    {
        return [
            'recipient_type' => 'employee',
            'recipient_id' => $payslip->employee_id,
            'recipient_name' => $payslip->employee_name,
            'recipient_iban' => $iban,
            'amount' => Dec::round((string) $payslip->payout_amount),
            'remittance_reference' => sprintf('Salario %02d/%d', $run->month, $run->year),
            'payroll_payment_id' => $payslip->id,
        ];
    }

    /**
     * @param  list<array{bank_account_id: string, employee_ids?: list<string>|null, department_id?: string|null}>  $allocations
     */
    private static function accountFor(?Employee $employee, array $allocations): ?string
    {
        foreach ($allocations as $allocation) {
            $ids = $allocation['employee_ids'] ?? null;
            $department = $allocation['department_id'] ?? null;

            if (($ids !== null && in_array((string) $employee?->id, $ids, true))
                || ($department !== null && $department === $employee?->department_id)
                || ($ids === null && $department === null)) {
                return $allocation['bank_account_id'];
            }
        }

        return null;
    }

    private function assertNotPaidAlready(PayrollRun $run): void
    {
        $active = PayrollPaymentBatch::query()
            ->with('batch')
            ->where('payroll_run_id', $run->id)
            ->get()
            ->filter(static fn (PayrollPaymentBatch $link): bool => $link->batch !== null && ! in_array(strtolower((string) $link->batch->status), self::DeadStatuses, true));

        if ($active->isNotEmpty()) {
            throw new PayrollException('Gli stipendi di questa elaborazione hanno già un file di pagamento.');
        }
    }
}
