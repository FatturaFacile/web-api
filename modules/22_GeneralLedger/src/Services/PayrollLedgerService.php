<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Exceptions\PayrollPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\PayrollLedgerPosting;
use Modules\GeneralLedger\Models\PayrollLedgerSetting;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Payroll\Calculation\Italian\ItalianWageCodes;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes;
use Modules\Payroll\Calculation\WageTypeKind;
use Modules\Payroll\Events\PayrollRunFinalizedEvent;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

/**
 * Posts a closed payroll run, one entry per run:
 *
 *   Dr salary cost, employer cost, expense allowances
 *   Cr employees (the payout), social security, tax authorities, benefits in kind
 *
 * The amounts come from the closing event; the kind of each code comes from the payslip lines. No account is assumed:
 * a missing mapping refuses the posting with a clear reason, the run stays closed, and it is posted once the mapping
 * or the period is there. A code with its own account in `wage_type_accounts` replaces the account of its role.
 */
final class PayrollLedgerService
{
    public const string SOURCE = 'payroll_run';

    /** Employee deductions due to the tax authorities. */
    private const array TAX_CODES = [SwissWageTypeCodes::WithholdingTax, ItalianWageCodes::Irpef, ItalianWageCodes::RegionalSurcharge, ItalianWageCodes::MunicipalSurcharge];

    /** Employee and employer contributions due to social insurers. */
    private const array SOCIAL_CODES = [
        SwissWageTypeCodes::Ahv, SwissWageTypeCodes::Alv, SwissWageTypeCodes::Nbu, SwissWageTypeCodes::Ktg, SwissWageTypeCodes::Bvg,
        SwissWageTypeCodes::AhvEmployer, SwissWageTypeCodes::AhvAdministration, SwissWageTypeCodes::AlvEmployer, SwissWageTypeCodes::BuEmployer,
        SwissWageTypeCodes::KtgEmployer, SwissWageTypeCodes::BvgEmployer, SwissWageTypeCodes::FakEmployer,
        ItalianWageCodes::InpsEmployee, ItalianWageCodes::InpsEmployer,
    ];

    /** A payout is rounded to 5 centimes per payslip, so it moves by less than this. */
    private const string ROUNDING_PER_PAYSLIP = '0.03';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
    ) {}

    /**
     * Keeps what the event says and tries to post it. A refusal is stored on the posting, not thrown: the closing of
     * the run is a payroll fact and stands on its own.
     */
    public function recordFinalized(PayrollRunFinalizedEvent $event): PayrollLedgerPosting
    {
        $posting = PayrollLedgerPosting::withoutGlobalScopes()->firstOrNew(['payroll_run_id' => $event->payrollRunId]);

        if ($posting->status === PayrollLedgerPosting::Posted) {
            return $posting;
        }

        if ($posting->status !== PayrollLedgerPosting::ReversalPending) {
            $posting->status = PayrollLedgerPosting::Pending;
            $posting->journal_entry_id = null;
        }

        $posting->id ??= (string) Str::uuid7();
        $posting->tenant_id = $event->tenantId;
        $posting->run_date = $event->paymentDate ?? CarbonImmutable::create($event->year, $event->month, 1)->endOfMonth()->toDateString();
        $posting->currency = $event->currency;
        $posting->payload = ['year' => $event->year, 'month' => $event->month, 'totals' => $event->totals, 'amounts_by_code' => $event->amountsByCode];
        $posting->save();

        try {
            return $this->post($posting);
        } catch (InvalidArgumentException $e) {
            Log::warning('Payroll run waits for the ledger.', ['payroll_run_id' => $event->payrollRunId, 'reason' => $e->getMessage()]);

            return $this->remember($posting, $e);
        }
    }

    /**
     * Reverses the entry of a reopened run on its own date or, when that period is closed, today. A run never posted
     * only leaves the queue.
     */
    public function recordReopened(string $payrollRunId, string $tenantId, string $reason): ?PayrollLedgerPosting
    {
        $posting = PayrollLedgerPosting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('payroll_run_id', $payrollRunId)->first();

        if ($posting === null) {
            return null;
        }

        if ($posting->status === PayrollLedgerPosting::Pending) {
            $posting->update(['status' => PayrollLedgerPosting::Withdrawn, 'last_error' => null]);

            return $posting;
        }

        if ($posting->status !== PayrollLedgerPosting::Posted) {
            return $posting;
        }

        $posting->update(['status' => PayrollLedgerPosting::ReversalPending]);

        try {
            return $this->reverse($posting, $reason);
        } catch (InvalidArgumentException $e) {
            Log::warning('Payroll reversal waits for an open period.', ['payroll_run_id' => $payrollRunId, 'reason' => $e->getMessage()]);

            return $this->remember($posting, $e);
        }
    }

    /**
     * Posts a run that was waiting, for the API. Here a refusal is thrown, so the caller reads the reason.
     *
     * @throws InvalidArgumentException
     */
    public function retry(PayrollLedgerPosting $posting): PayrollLedgerPosting
    {
        try {
            return $posting->status === PayrollLedgerPosting::ReversalPending
                ? $this->reverse($posting, 'Payroll run reopened')
                : $this->post($posting);
        } catch (InvalidArgumentException $e) {
            $this->remember($posting, $e);

            throw $e;
        }
    }

    private function post(PayrollLedgerPosting $posting): PayrollLedgerPosting
    {
        if ($posting->status === PayrollLedgerPosting::Posted) {
            return $posting;
        }

        if ($posting->status !== PayrollLedgerPosting::Pending) {
            throw new InvalidArgumentException("A payroll run in status [{$posting->status}] cannot be posted.");
        }

        $tenantId = (string) $posting->tenant_id;
        $date = CarbonImmutable::parse($posting->run_date)->toDateString();
        $accountingCurrency = TenantAccountingCurrency::of($tenantId);

        if ($posting->currency !== $accountingCurrency) {
            throw PayrollPostingException::foreignCurrency((string) $posting->currency, $accountingCurrency);
        }

        $lines = $this->lines($posting, $tenantId);
        $period = $this->periods->forDate($tenantId, $date);

        return DB::transaction(function () use ($posting, $tenantId, $date, $lines, $period): PayrollLedgerPosting {
            $label = sprintf('Payroll %04d-%02d', $posting->payload['year'], $posting->payload['month']);

            $entry = $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'PAYROLL-'.strtoupper(Str::random(10)),
                'source_document_type' => self::SOURCE,
                'source_document_id' => (string) $posting->payroll_run_id,
                'document_number' => $label,
                'posting_date' => $date,
                'document_date' => $date,
                'journal_type' => 'standard',
                'description' => $label,
                'lines' => $lines,
            ]);

            $posting->update(['status' => PayrollLedgerPosting::Posted, 'journal_entry_id' => $entry->id, 'last_error' => null]);

            return $posting;
        });
    }

    private function reverse(PayrollLedgerPosting $posting, string $reason): PayrollLedgerPosting
    {
        $tenantId = (string) $posting->tenant_id;

        $date = CarbonImmutable::parse($posting->run_date)->toDateString();

        try {
            $this->periods->forDate($tenantId, $date);
        } catch (FiscalPeriodException) {
            $date = now()->toDateString();
            $this->periods->forDate($tenantId, $date);
        }

        return DB::transaction(function () use ($posting, $date, $reason): PayrollLedgerPosting {
            $this->postingService->reverseJournal((string) $posting->journal_entry_id, $date, $reason);
            $posting->update(['status' => PayrollLedgerPosting::Reversed, 'last_error' => null]);

            return $posting;
        });
    }

    private function remember(PayrollLedgerPosting $posting, InvalidArgumentException $e): PayrollLedgerPosting
    {
        $posting->update(['last_error' => $e->getMessage()]);

        return $posting;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws PayrollPostingException
     */
    private function lines(PayrollLedgerPosting $posting, string $tenantId): array
    {
        $settings = PayrollLedgerSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $kinds = $this->kindsOf((string) $posting->payroll_run_id);
        $overrides = $settings?->wage_type_accounts ?? [];

        /** @var array<string, array{debit: string, credit: string, role: string}> $buckets */
        $buckets = [];
        $add = static function (string $accountId, string $side, string $amount, string $role) use (&$buckets): void {
            $buckets[$accountId] ??= ['debit' => '0', 'credit' => '0', 'role' => $role];
            $buckets[$accountId][$side] = Decimal::add($buckets[$accountId][$side], $amount);
        };

        foreach ($posting->payload['amounts_by_code'] as $code => $amount) {
            $code = (string) $code;
            $kind = $kinds[$code] ?? WageTypeKind::Information;
            $override = $overrides[$code] ?? null;
            $value = Decimal::abs((string) $amount);

            if (Decimal::isZero($value)) {
                continue;
            }

            match ($kind) {
                WageTypeKind::Earning => $add($override ?? $this->account($settings, 'salary_expense', 'salary cost'), Decimal::isNegative((string) $amount) ? 'credit' : 'debit', $value, 'salary cost'),
                WageTypeKind::NonCash => $this->addNonCash($add, $settings, $override, $value),
                WageTypeKind::Expense => $add($override ?? $this->account($settings, 'expense_allowance', 'expense allowance'), 'debit', $value, 'expense allowance'),
                WageTypeKind::Deduction => $add($this->liabilityAccount($settings, $override, $code, $kind), 'credit', $value, 'deduction'),
                WageTypeKind::Employer => $this->addEmployerCost($add, $settings, $override, $code, $value),
                default => null,
            };
        }

        $payout = (string) $posting->payload['totals']['payout_amount'];

        if (Decimal::isPositive($payout)) {
            $add($this->account($settings, 'employee_payable', 'employee payable'), 'credit', $payout, 'employee payable');
        }

        $this->addRounding($add, $settings, $buckets, count(Payslip::withoutGlobalScopes()->where('payroll_run_id', $posting->payroll_run_id)->pluck('id')));

        return $this->toJournalLines($buckets, $tenantId, (string) $posting->currency);
    }

    /**
     * A benefit in kind is salary that is not paid out: it is a cost and is credited to its own account.
     */
    private function addNonCash(callable $add, ?PayrollLedgerSetting $settings, ?string $override, string $value): void
    {
        $add($this->account($settings, 'salary_expense', 'salary cost'), 'debit', $value, 'salary cost');
        $add($override ?? $this->account($settings, 'non_cash_benefit', 'benefit in kind'), 'credit', $value, 'benefit in kind');
    }

    private function addEmployerCost(callable $add, ?PayrollLedgerSetting $settings, ?string $override, string $code, string $value): void
    {
        $add($this->account($settings, 'employer_cost', 'employer cost'), 'debit', $value, 'employer cost');
        $add($this->liabilityAccount($settings, $override, $code, WageTypeKind::Employer), 'credit', $value, 'contribution');
    }

    private function liabilityAccount(?PayrollLedgerSetting $settings, ?string $override, string $code, string $kind): string
    {
        return match (true) {
            $override !== null => $override,
            in_array($code, self::TAX_CODES, true) => $this->account($settings, 'tax_payable', 'tax payable'),
            in_array($code, self::SOCIAL_CODES, true) => $this->account($settings, 'social_payable', 'social contributions payable'),
            default => throw PayrollPostingException::unmappedWageType($code, $kind),
        };
    }

    /**
     * Rounding the payouts leaves a few centimes per payslip; anything more is an error in the run.
     *
     * @param  array<string, array{debit: string, credit: string, role: string}>  $buckets
     */
    private function addRounding(callable $add, ?PayrollLedgerSetting $settings, array $buckets, int $payslips): void
    {
        $difference = '0';

        foreach ($buckets as $bucket) {
            $difference = Decimal::add($difference, Decimal::sub($bucket['debit'], $bucket['credit']));
        }

        if (Decimal::isZero($difference)) {
            return;
        }

        if (Decimal::compare(Decimal::abs($difference), Decimal::mul(self::ROUNDING_PER_PAYSLIP, (string) max(1, $payslips))) > 0) {
            throw PayrollPostingException::unbalanced(Decimal::round2($difference));
        }

        $add($this->account($settings, 'rounding', 'payroll rounding'), Decimal::isPositive($difference) ? 'credit' : 'debit', Decimal::abs($difference), 'payroll rounding');
    }

    /**
     * @param  array<string, array{debit: string, credit: string, role: string}>  $buckets
     * @return list<array<string, mixed>>
     */
    private function toJournalLines(array $buckets, string $tenantId, string $currency): array
    {
        $lines = [];

        foreach ($buckets as $accountId => $bucket) {
            if (! ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->exists()) {
                throw PayrollPostingException::missingAccount($bucket['role']);
            }

            $net = Decimal::sub($bucket['debit'], $bucket['credit']);

            if (Decimal::isZero($net)) {
                continue;
            }

            $lines[] = [
                'account_id' => $accountId,
                'debit_amount' => Decimal::isPositive($net) ? $net : '0',
                'credit_amount' => Decimal::isNegative($net) ? Decimal::abs($net) : '0',
                'currency' => $currency,
                'description' => 'Payroll: '.$bucket['role'],
            ];
        }

        return $lines;
    }

    private function account(?PayrollLedgerSetting $settings, string $role, string $label): string
    {
        return $settings?->{"{$role}_account_id"} ?? throw PayrollPostingException::missingAccount($label);
    }

    /**
     * @return array<string, string>
     */
    private function kindsOf(string $payrollRunId): array
    {
        $payslipIds = Payslip::withoutGlobalScopes()->where('payroll_run_id', $payrollRunId)->pluck('id');

        return PayslipLine::withoutGlobalScopes()->whereIn('payslip_id', $payslipIds)->pluck('kind', 'wage_type_code')->all();
    }

    /**
     * The entry of a run, for checks and reports.
     */
    public function entryOf(string $payrollRunId, string $tenantId): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('source_document_type', self::SOURCE)
            ->where('source_document_id', $payrollRunId)
            ->where('status', 'posted')
            ->where('journal_type', '!=', 'reversing')
            ->first();
    }
}
