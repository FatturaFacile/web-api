<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Events\BankPostingReadyEvent;
use Modules\GeneralLedger\Events\JournalPostedEvent;
use Modules\GeneralLedger\Events\JournalReversedEvent;
use Modules\GeneralLedger\Events\PayableReadyEvent;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\SupplierLedger;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;
use RoundingMode;

final class DoubleEntryPostingService implements DoubleEntryPostingServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger
    ) {}

    public function validateBalance(array $lines): bool
    {
        if (empty($lines)) {
            return false;
        }

        $totalDebit = '0';
        $totalCredit = '0';

        foreach ($lines as $line) {
            $debit = $this->ledgerAmount($line['debit_amount'] ?? 0);
            $credit = $this->ledgerAmount($line['credit_amount'] ?? 0);

            if (Decimal::isNegative($debit) || Decimal::isNegative($credit)) {
                return false;
            }

            $totalDebit = Decimal::add($totalDebit, $debit);
            $totalCredit = Decimal::add($totalCredit, $credit);
        }

        return Decimal::compare($totalDebit, $totalCredit) === 0 && Decimal::isPositive($totalDebit);
    }

    public function createAndPost(array $data, ?string $actorUserId = null): JournalEntry
    {
        if (($data['journal_type'] ?? '') === 'closing') {
            throw new InvalidArgumentException('Closing entries are generated only by the fiscal year closing.');
        }

        return $this->persist($data, $actorUserId, false);
    }

    public function postYearEndClosing(array $data, string $actorUserId): JournalEntry
    {
        return $this->persist(['journal_type' => 'closing'] + $data, $actorUserId, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, ?string $actorUserId, bool $isYearEndClosing): JournalEntry
    {
        $data['company_id'] = ! empty($data['company_id']) ? $data['company_id'] : null;
        $actorUuid = ! empty($actorUserId) ? (Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7()) : null;

        return DB::transaction(function () use ($data, $actorUuid, $isYearEndClosing) {
            $lines = $data['lines'] ?? [];

            if (! $this->validateBalance($lines)) {
                throw new InvalidArgumentException('Journal entry lines must balance with positive debit/credit values.');
            }

            $fiscalPeriod = FiscalPeriod::findOrFail($data['fiscal_period_id']);

            if ((string) $fiscalPeriod->tenant_id !== (string) $data['tenant_id']) {
                throw FiscalPeriodException::foreignPeriod((string) $fiscalPeriod->period_name);
            }

            $postingDate = isset($data['posting_date'])
                ? CarbonImmutable::parse($data['posting_date'])->toDateString()
                : $this->dateWithin($fiscalPeriod);
            $this->assertDateInPeriod($postingDate, $fiscalPeriod);

            if ($fiscalPeriod->isLockedFor('GL') && ! $isYearEndClosing) {
                throw new InvalidArgumentException("Fiscal period [{$fiscalPeriod->period_name}] is locked for General Ledger postings.");
            }

            $totalAmount = '0';
            foreach ($lines as $line) {
                $totalAmount = Decimal::add($totalAmount, $this->ledgerAmount($line['debit_amount'] ?? 0));
            }

            $entry = JournalEntry::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'] ?? null,
                'batch_id' => $data['batch_id'] ?? null,
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => $data['entry_number'] ?? 'JE-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                'posting_date' => $postingDate,
                'document_date' => $data['document_date'] ?? $postingDate,
                'ledger_type' => $data['ledger_type'] ?? 'statutory',
                'journal_type' => $data['journal_type'] ?? 'standard',
                'description' => $data['description'] ?? 'Journal Entry',
                'source_document_type' => $data['source_document_type'] ?? null,
                'source_document_id' => $data['source_document_id'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'workflow_instance_id' => $data['workflow_instance_id'] ?? null,
                'status' => $data['status'] ?? 'posted',
                'total_amount' => $this->ledgerAmount($totalAmount),
                'created_by' => $actorUuid,
                'posted_at' => ($data['status'] ?? 'posted') === 'posted' ? now() : null,
                'posted_by' => ($data['status'] ?? 'posted') === 'posted' ? $actorUuid : null,
            ]);

            $tenantCurrency = TenantAccountingCurrency::of((string) $entry->tenant_id);
            $lineNumber = 1;
            foreach ($lines as $line) {
                $account = ChartOfAccount::findOrFail($line['account_id']);
                $debit = $this->ledgerAmount($line['debit_amount'] ?? 0);
                $credit = $this->ledgerAmount($line['credit_amount'] ?? 0);
                $currency = $line['currency'] ?? $tenantCurrency;
                $rate = number_format((float) ($line['exchange_rate'] ?? 1.0), 6, '.', '');
                $functionalAmt = $this->ledgerAmount(Decimal::mul(Decimal::isPositive($debit) ? $debit : $credit, $rate));

                $journalLine = JournalLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $entry->tenant_id,
                    'journal_entry_id' => $entry->id,
                    'posting_date' => $entry->posting_date,
                    'line_number' => $lineNumber++,
                    'account_id' => $account->id,
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                    'profit_center_id' => $line['profit_center_id'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                    'partner_type' => $line['partner_type'] ?? null,
                    'partner_id' => $line['partner_id'] ?? null,
                    'description' => $line['description'] ?? $entry->description,
                    'debit_amount' => $debit,
                    'credit_amount' => $credit,
                    'currency' => $currency,
                    'exchange_rate' => $rate,
                    'amount_in_functional_currency' => $functionalAmt,
                    'vat_code_id' => $line['vat_code_id'] ?? null,
                    'vat_base_amount' => (float) ($line['vat_base_amount'] ?? 0.0),
                    'vat_tax_amount' => (float) ($line['vat_tax_amount'] ?? 0.0),
                    'vat_rate_percent' => $line['vat_rate_percent'] ?? null,
                    'vat_treatment' => $line['vat_treatment'] ?? null,
                    'is_cleared' => (bool) ($line['is_cleared'] ?? false) || ! empty($line['settles_entry_id']),
                    'clearing_reference' => $line['settles_entry_id'] ?? $line['clearing_reference'] ?? null,
                ]);

                // Subledger & Treasury integrations
                if ($entry->status === 'posted') {
                    $this->processSubledgerAndTreasury($entry, $journalLine, $account, $line['due_date'] ?? null, (bool) ($data['bank_balance_managed'] ?? false));
                    $this->settleOpenItems($entry, $journalLine, $line['settles_entry_id'] ?? null);
                }
            }

            if ($entry->status === 'posted') {
                $this->auditLedger->record(
                    action: 'created',
                    model: $entry,
                    payloadBefore: null,
                    payloadAfter: $entry->toArray(),
                    moduleCode: 'MODULE_22_GENERAL_LEDGER'
                );

                $event = new JournalPostedEvent(
                    journalEntryId: (string) $entry->id,
                    entryNumber: (string) $entry->entry_number,
                    fiscalPeriodId: (string) $entry->fiscal_period_id,
                    totalAmount: (float) $entry->total_amount,
                    ledgerType: (string) $entry->ledger_type,
                    journalType: (string) $entry->journal_type,
                    postingDate: (string) $entry->posting_date,
                    tenantId: (string) $entry->tenant_id,
                    companyId: (string) ($entry->company_id ?? '')
                );

                $this->dispatcher->publish($event);
                event($event);
            }

            return $entry->load('lines');
        });
    }

    public function postDraft(string $journalEntryId, ?string $actorUserId = null): JournalEntry
    {
        $actorUuid = ! empty($actorUserId) ? (Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7()) : null;

        return DB::transaction(function () use ($journalEntryId, $actorUuid) {
            /** @var JournalEntry $entry */
            $entry = JournalEntry::where('id', $journalEntryId)->lockForUpdate()->firstOrFail();

            if ($entry->status === 'posted') {
                return $entry;
            }

            if ($entry->status === 'reversed') {
                throw new InvalidArgumentException('Cannot post an already reversed journal entry.');
            }

            $fiscalPeriod = FiscalPeriod::findOrFail($entry->fiscal_period_id);
            $this->assertDateInPeriod(CarbonImmutable::parse($entry->posting_date)->toDateString(), $fiscalPeriod);

            if ($fiscalPeriod->isLockedFor('GL')) {
                throw new InvalidArgumentException("Fiscal period [{$fiscalPeriod->period_name}] is locked for postings.");
            }

            if (! $entry->isBalanced()) {
                throw new InvalidArgumentException('Journal entry lines must balance before posting.');
            }

            $before = $entry->toArray();

            $entry->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actorUuid,
            ]);

            foreach ($entry->lines as $line) {
                $account = ChartOfAccount::find($line->account_id);
                if ($account) {
                    $this->processSubledgerAndTreasury($entry, $line, $account);
                }
            }

            $this->auditLedger->record(
                action: 'posted',
                model: $entry,
                payloadBefore: $before,
                payloadAfter: $entry->toArray(),
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            $event = new JournalPostedEvent(
                journalEntryId: (string) $entry->id,
                entryNumber: (string) $entry->entry_number,
                fiscalPeriodId: (string) $entry->fiscal_period_id,
                totalAmount: (float) $entry->total_amount,
                ledgerType: (string) $entry->ledger_type,
                journalType: (string) $entry->journal_type,
                postingDate: (string) $entry->posting_date,
                tenantId: (string) $entry->tenant_id,
                companyId: (string) ($entry->company_id ?? '')
            );

            $this->dispatcher->publish($event);
            event($event);

            return $entry;
        });
    }

    public function reverseJournal(string $journalEntryId, string $reversalDate, ?string $reason = null, ?string $actorUserId = null): JournalEntry
    {
        $actorUuid = ! empty($actorUserId) ? (Str::isUuid($actorUserId) ? $actorUserId : (string) Str::uuid7()) : null;

        return DB::transaction(function () use ($journalEntryId, $reversalDate, $reason, $actorUuid) {
            /** @var JournalEntry $original */
            $original = JournalEntry::where('id', $journalEntryId)->lockForUpdate()->firstOrFail();

            if ($original->status !== 'posted') {
                throw new InvalidArgumentException('Only posted journal entries can be reversed.');
            }

            if (in_array($original->source_document_type, [VatLedgerReader::SOURCE_SETTLEMENT, VatLedgerReader::SOURCE_SETTLEMENT_PAYMENT], true)) {
                throw new InvalidArgumentException('The entry of a posted VAT settlement cannot be reversed: correct the next period.');
            }

            // Find target period for reversal date
            $targetPeriod = FiscalPeriod::where('tenant_id', $original->tenant_id)
                ->where('start_date', '<=', $reversalDate)
                ->where('end_date', '>=', $reversalDate)
                ->first();

            if (! $targetPeriod) {
                throw FiscalPeriodException::missing(CarbonImmutable::parse($reversalDate)->toDateString());
            }

            if ($targetPeriod->isLockedFor('GL')) {
                throw new InvalidArgumentException("Target fiscal period [{$targetPeriod->period_name}] is locked for reversals.");
            }

            $reversalEntry = JournalEntry::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $original->tenant_id,
                'company_id' => $original->company_id,
                'batch_id' => $original->batch_id,
                'fiscal_period_id' => $targetPeriod->id,
                'entry_number' => 'REV-'.$original->entry_number,
                'posting_date' => $reversalDate,
                'document_date' => $reversalDate,
                'ledger_type' => $original->ledger_type,
                'journal_type' => 'reversing',
                'reversal_journal_entry_id' => $original->id,
                'description' => 'Reversal of '.$original->entry_number.($reason ? ': '.$reason : ''),
                'source_document_type' => $original->source_document_type,
                'source_document_id' => $original->source_document_id,
                'document_number' => $original->document_number,
                'status' => 'posted',
                'total_amount' => $original->total_amount,
                'created_by' => $actorUuid,
                'posted_at' => now(),
                'posted_by' => $actorUuid,
            ]);

            $lineNum = 1;
            foreach ($original->lines as $line) {
                $reversalLine = JournalLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $original->tenant_id,
                    'journal_entry_id' => $reversalEntry->id,
                    'posting_date' => $reversalDate,
                    'line_number' => $lineNum++,
                    'account_id' => $line->account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'profit_center_id' => $line->profit_center_id,
                    'project_id' => $line->project_id,
                    'partner_type' => $line->partner_type,
                    'partner_id' => $line->partner_id,
                    'description' => 'Reversal: '.$line->description,
                    // Swap Dr and Cr
                    'debit_amount' => $line->credit_amount,
                    'credit_amount' => $line->debit_amount,
                    'currency' => $line->currency,
                    'exchange_rate' => $line->exchange_rate,
                    'amount_in_functional_currency' => $line->amount_in_functional_currency,
                    'vat_code_id' => $line->vat_code_id,
                    'vat_base_amount' => $line->vat_base_amount,
                    'vat_tax_amount' => $line->vat_tax_amount,
                    'vat_rate_percent' => $line->vat_rate_percent,
                    'vat_treatment' => $line->vat_treatment,
                    'is_cleared' => true,
                    'clearing_reference' => mb_substr('REV-'.$original->entry_number, 0, 50),
                ]);

                $this->reverseSubledger($original, $reversalEntry, $line, $reversalLine);

                $account = ChartOfAccount::find($line->account_id);

                if ($account !== null) {
                    $this->emitBankPosting($reversalEntry, $reversalLine, $account);
                }
            }

            $before = $original->toArray();
            $original->update([
                'status' => 'reversed',
                'reversal_date' => $reversalDate,
                'reversal_journal_entry_id' => $reversalEntry->id,
                'reversed_at' => now(),
                'reversed_by' => $actorUuid,
            ]);

            $this->auditLedger->record(
                action: 'reversed',
                model: $original,
                payloadBefore: $before,
                payloadAfter: $original->toArray(),
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            $event = new JournalReversedEvent(
                originalJournalEntryId: (string) $original->id,
                reversalJournalEntryId: (string) $reversalEntry->id,
                reversalEntryNumber: (string) $reversalEntry->entry_number,
                totalAmount: (float) $original->total_amount,
                reversalDate: $reversalDate,
                tenantId: (string) $original->tenant_id,
                companyId: (string) ($original->company_id ?? '')
            );

            $this->dispatcher->publish($event);
            event($event);

            return $reversalEntry->load('lines');
        });
    }

    /**
     * Amount at the scale of the ledger columns. Floats are read at that scale, so their binary noise never reaches
     * the comparison.
     */
    private function ledgerAmount(mixed $value): string
    {
        $text = is_float($value) ? number_format($value, 4, '.', '') : (string) ($value ?? '0');

        return bcround(Decimal::normalize($text === '' ? '0' : $text), 4, RoundingMode::HalfAwayFromZero);
    }

    /**
     * A caller that names the period but no date books today, or the nearest day of that period.
     */
    private function dateWithin(FiscalPeriod $period): string
    {
        $today = now()->toDateString();
        $start = CarbonImmutable::parse($period->start_date)->toDateString();
        $end = CarbonImmutable::parse($period->end_date)->toDateString();

        return max($start, min($today, $end));
    }

    private function assertDateInPeriod(string $postingDate, FiscalPeriod $period): void
    {
        $start = CarbonImmutable::parse($period->start_date)->toDateString();
        $end = CarbonImmutable::parse($period->end_date)->toDateString();

        if ($postingDate < $start || $postingDate > $end) {
            throw FiscalPeriodException::dateOutsidePeriod($postingDate, (string) $period->period_name);
        }
    }

    /**
     * Closes the open item of the entry a payment settles. The item of the payment itself is then fully consumed.
     */
    private function settleOpenItems(JournalEntry $entry, JournalLine $line, ?string $settledEntryId): void
    {
        if ($settledEntryId === null || $settledEntryId === '') {
            return;
        }

        $ledger = $line->partner_type === 'supplier' ? SupplierLedger::class : CustomerLedger::class;
        $paymentItem = $ledger::query()->where('journal_entry_id', $entry->id)->orderByDesc('created_at')->lockForUpdate()->first();
        $item = $ledger::query()->where('journal_entry_id', $settledEntryId)->lockForUpdate()->first();

        if ($paymentItem === null || $item === null) {
            return;
        }

        $open = Decimal::add((string) $item->open_amount, (string) $paymentItem->open_amount);
        $item->update(['open_amount' => $this->ledgerAmount($open), 'status' => Decimal::isZero($open) ? 'settled' : 'partially_paid']);
        $paymentItem->update(['open_amount' => 0, 'status' => 'settled']);
    }

    /**
     * Mirrors in the subledgers what the reversal does in the ledger: a reversed payment reopens the item it had
     * settled; any other entry loses its open items, and only what had already been settled stays as a counter item.
     */
    private function reverseSubledger(JournalEntry $original, JournalEntry $reversal, JournalLine $line, JournalLine $reversalLine): void
    {
        $ledger = match ($line->partner_type) {
            'customer' => CustomerLedger::class,
            'supplier' => SupplierLedger::class,
            default => null,
        };

        if ($ledger === null || $line->partner_id === null) {
            return;
        }

        $isCustomer = $line->partner_type === 'customer';
        $partnerColumn = $isCustomer ? 'customer_id' : 'supplier_id';
        $counterAmount = $isCustomer
            ? Decimal::sub((string) $line->credit_amount, (string) $line->debit_amount)
            : Decimal::sub((string) $line->debit_amount, (string) $line->credit_amount);
        $item = $ledger::query()->where('journal_entry_id', $original->id)->where($partnerColumn, $line->partner_id)->lockForUpdate()->first();
        $counterOpen = $counterAmount;

        if ($item !== null && $line->clearing_reference !== null && Str::isUuid($line->clearing_reference)) {
            $settled = $ledger::query()->where('journal_entry_id', $line->clearing_reference)->lockForUpdate()->first();

            if ($settled !== null) {
                $open = Decimal::add((string) $settled->open_amount, $counterAmount);
                $untouched = Decimal::compare($open, (string) $settled->original_amount) === 0;
                $settled->update(['open_amount' => $this->ledgerAmount($open), 'status' => $untouched ? 'open' : (Decimal::isZero($open) ? 'settled' : 'partially_paid')]);
                $counterOpen = '0';
            }
        } elseif ($item !== null) {
            $counterOpen = Decimal::add($counterAmount, (string) $item->open_amount);
            $item->update(['open_amount' => 0, 'status' => 'settled']);
        }

        $ledger::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $reversal->tenant_id,
            'company_id' => $reversal->company_id,
            $partnerColumn => $line->partner_id,
            'journal_entry_id' => $reversal->id,
            'posting_date' => $reversal->posting_date,
            'due_date' => $reversal->posting_date,
            'original_amount' => $this->ledgerAmount($counterAmount),
            'open_amount' => $this->ledgerAmount($counterOpen),
            'currency' => $reversalLine->currency,
            'status' => Decimal::isZero($counterOpen) ? 'settled' : 'open',
        ]);
    }

    private function emitBankPosting(JournalEntry $entry, JournalLine $line, ChartOfAccount $account): void
    {
        if ($account->reconciliation_type !== 'bank' && ! str_contains(strtolower($account->name), 'bank')) {
            return;
        }

        $amt = (float) $line->debit_amount > 0 ? (float) $line->debit_amount : (float) $line->credit_amount;
        $dir = (float) $line->debit_amount > 0 ? 'debit' : 'credit';

        $ev = new BankPostingReadyEvent(
            journalLineId: (string) $line->id,
            bankAccountId: (string) $account->id,
            amount: $amt,
            direction: $dir,
            valueDate: (string) $entry->posting_date,
            journalEntryId: (string) $entry->id,
            tenantId: (string) $entry->tenant_id,
            companyId: (string) ($entry->company_id ?? '')
        );
        $this->dispatcher->publish($ev);
        event($ev);
    }

    private function processSubledgerAndTreasury(JournalEntry $entry, JournalLine $line, ChartOfAccount $account, ?string $dueDate = null, bool $bankBalanceManaged = false): void
    {
        $dueDate ??= date('Y-m-d', strtotime((string) $entry->posting_date.' +30 days'));

        // 1. Accounts Receivable / Customer Subledger
        if ($account->reconciliation_type === 'customer' || $line->partner_type === 'customer') {
            if ($line->partner_id) {
                $amount = (float) $line->debit_amount > 0 ? (float) $line->debit_amount : -((float) $line->credit_amount);

                $subItem = CustomerLedger::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $entry->tenant_id,
                    'company_id' => $entry->company_id,
                    'customer_id' => $line->partner_id,
                    'journal_entry_id' => $entry->id,
                    'posting_date' => $entry->posting_date,
                    'due_date' => $dueDate,
                    'original_amount' => $amount,
                    'open_amount' => $amount,
                    'currency' => $line->currency,
                    'status' => 'open',
                ]);

                if ($amount > 0) {
                    $ev = new ReceivableReadyEvent(
                        customerLedgerId: (string) $subItem->id,
                        customerId: (string) $line->partner_id,
                        openAmount: $amount,
                        currency: $line->currency,
                        dueDate: (string) $subItem->due_date,
                        journalEntryId: (string) $entry->id,
                        tenantId: (string) $entry->tenant_id,
                        companyId: (string) (! empty($entry->company_id) ? $entry->company_id : $entry->tenant_id)
                    );
                    $this->dispatcher->publish($ev);
                    event($ev);
                }
            }
        }

        // 2. Accounts Payable / Supplier Subledger
        if ($account->reconciliation_type === 'supplier' || $line->partner_type === 'supplier') {
            if ($line->partner_id) {
                $amount = (float) $line->credit_amount > 0 ? (float) $line->credit_amount : -((float) $line->debit_amount);

                $subItem = SupplierLedger::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $entry->tenant_id,
                    'company_id' => $entry->company_id,
                    'supplier_id' => $line->partner_id,
                    'journal_entry_id' => $entry->id,
                    'posting_date' => $entry->posting_date,
                    'due_date' => $dueDate,
                    'original_amount' => $amount,
                    'open_amount' => $amount,
                    'currency' => $line->currency,
                    'status' => 'open',
                ]);

                if ($amount > 0) {
                    $ev = new PayableReadyEvent(
                        supplierLedgerId: (string) $subItem->id,
                        supplierId: (string) $line->partner_id,
                        openAmount: $amount,
                        currency: $line->currency,
                        dueDate: (string) $subItem->due_date,
                        journalEntryId: (string) $entry->id,
                        tenantId: (string) $entry->tenant_id,
                        companyId: (string) (! empty($entry->company_id) ? $entry->company_id : $entry->tenant_id)
                    );
                    $this->dispatcher->publish($ev);
                    event($ev);
                }
            }
        }

        // 3. Bank Postings / Treasury
        if (! $bankBalanceManaged) {
            $this->emitBankPosting($entry, $line, $account);
        }
    }
}
