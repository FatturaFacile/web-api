<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\Treasury\Events\BankMovementPostedEvent;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankTransaction;
use Modules\Treasury\Models\TreasuryBankMovement;

/**
 * Bank movements originated by treasury, each posted to the ledger and to the balances of the treasury bank accounts
 * in one transaction, with the bank accounts locked:
 *
 * - internal transfer: Dr ledger account of the receiving bank / Cr ledger account of the paying bank, same currency;
 * - bank fee: Dr `bank_charges_account_code` / Cr bank;
 * - interest income: Dr bank / Cr `interest_income_account_code`;
 * - interest expense: Dr `interest_expense_account_code` / Cr bank.
 *
 * Amounts are decimal strings (bcmath). The idempotency key of the request makes a retry return the movement already
 * recorded instead of posting it twice.
 */
final readonly class TreasuryBankMovementService
{
    /**
     * The `banking.ledger_defaults` preference of the counter account of each charge type.
     *
     * @var array<string, string>
     */
    private const array CHARGE_ACCOUNTS = [
        TreasuryBankMovement::TypeBankFee => 'bank_charges_account_code',
        TreasuryBankMovement::TypeInterestIncome => 'interest_income_account_code',
        TreasuryBankMovement::TypeInterestExpense => 'interest_expense_account_code',
    ];

    public function __construct(
        private TreasuryLedgerPoster $poster,
        private TreasuryLedgerAccounts $accounts,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Moves money between two bank accounts of the tenant and posts it on both ledger accounts.
     *
     * @param  array{from_bank_account_id: string, to_bank_account_id: string, amount: numeric-string|float|int, transfer_date: string, description?: ?string, idempotency_key: string}  $data
     *
     * @throws TreasuryOperationException
     */
    public function transfer(string $tenantId, array $data, ?string $userId): TreasuryBankMovement
    {
        return $this->idempotent($tenantId, $data['idempotency_key'], fn (): TreasuryBankMovement => DB::transaction(function () use ($tenantId, $data, $userId): TreasuryBankMovement {
            $existing = $this->existing($tenantId, $data['idempotency_key']);

            if ($existing !== null) {
                return $existing;
            }

            $amount = $this->positive($data['amount']);
            [$from, $to] = $this->lockPair($data['from_bank_account_id'], $data['to_bank_account_id']);

            if ($from->currency !== $to->currency) {
                throw new TreasuryOperationException('An internal transfer moves money between accounts in the same currency.', 'to_bank_account_id');
            }

            $description = (string) ($data['description'] ?? "Transfer {$from->iban} > {$to->iban}");
            $movement = $this->record($tenantId, TreasuryBankMovement::TypeTransfer, $from, $to, $amount, $data['transfer_date'], $description, $data['idempotency_key'], $userId);

            $entry = $this->poster->post($tenantId, 'treasury_transfer', (string) $movement->id, $data['transfer_date'], $data['idempotency_key'], $description, [
                ['account' => $this->accounts->bank($to, 'to_bank_account_id'), 'debit' => $amount, 'credit' => '0', 'description' => $description, 'currency' => (string) $to->currency],
                ['account' => $this->accounts->bank($from, 'from_bank_account_id'), 'debit' => '0', 'credit' => $amount, 'description' => $description, 'currency' => (string) $from->currency],
            ], true, $userId);

            $this->moveBalance($from, bcmul($amount, '-1', 2), $data['transfer_date'], $description, (string) $entry->id);
            $this->moveBalance($to, $amount, $data['transfer_date'], $description, (string) $entry->id);

            return $this->finish($movement, (string) $entry->id);
        }));
    }

    /**
     * Posts a bank fee or interest on a bank account.
     *
     * @param  array{movement_type: string, amount: numeric-string|float|int, movement_date: string, description?: ?string, idempotency_key: string}  $data
     *
     * @throws TreasuryOperationException
     */
    public function charge(string $tenantId, string $bankAccountId, array $data, ?string $userId): TreasuryBankMovement
    {
        return $this->idempotent($tenantId, $data['idempotency_key'], fn (): TreasuryBankMovement => DB::transaction(function () use ($tenantId, $bankAccountId, $data, $userId): TreasuryBankMovement {
            $existing = $this->existing($tenantId, $data['idempotency_key']);

            if ($existing !== null) {
                return $existing;
            }

            $type = $data['movement_type'];
            $amount = $this->positive($data['amount']);
            $account = BankAccount::query()->whereKey($bankAccountId)->lockForUpdate()->firstOrFail();
            $counter = $this->accounts->configured($tenantId, self::CHARGE_ACCOUNTS[$type], 'movement_type');
            $bank = $this->accounts->bank($account, 'bank_account_id');
            $description = (string) ($data['description'] ?? str_replace('_', ' ', ucfirst($type))." {$account->iban}");
            $movement = $this->record($tenantId, $type, $account, null, $amount, $data['movement_date'], $description, $data['idempotency_key'], $userId);
            $isIncome = $type === TreasuryBankMovement::TypeInterestIncome;
            $currency = (string) $account->currency;

            $entry = $this->poster->post($tenantId, 'treasury_'.$type, (string) $movement->id, $data['movement_date'], $data['idempotency_key'], $description, [
                $this->line($isIncome ? $bank : $counter, $amount, '0', $description, $currency),
                $this->line($isIncome ? $counter : $bank, '0', $amount, $description, $currency),
            ], true, $userId);

            $this->moveBalance($account, $isIncome ? $amount : bcmul($amount, '-1', 2), $data['movement_date'], $description, (string) $entry->id);

            return $this->finish($movement, (string) $entry->id);
        }));
    }

    /**
     * Runs a recording; when a concurrent request with the same key won the race, its movement is returned.
     *
     * @param  callable(): TreasuryBankMovement  $record
     */
    private function idempotent(string $tenantId, string $key, callable $record): TreasuryBankMovement
    {
        try {
            return $record();
        } catch (UniqueConstraintViolationException $e) {
            return TreasuryBankMovement::query()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first() ?? throw $e;
        }
    }

    private function existing(string $tenantId, string $key): ?TreasuryBankMovement
    {
        return TreasuryBankMovement::query()->where('tenant_id', $tenantId)->where('idempotency_key', $key)->lockForUpdate()->first();
    }

    /**
     * @return array{0: BankAccount, 1: BankAccount}
     */
    private function lockPair(string $fromId, string $toId): array
    {
        if ($fromId === $toId) {
            throw new TreasuryOperationException('The two bank accounts of a transfer must differ.', 'to_bank_account_id');
        }

        $locked = BankAccount::query()->whereIn('id', [$fromId, $toId])->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($locked->count() !== 2) {
            throw new TreasuryOperationException('Both bank accounts must be active accounts of the company.', 'to_bank_account_id');
        }

        return [$locked[$fromId], $locked[$toId]];
    }

    private function record(string $tenantId, string $type, BankAccount $account, ?BankAccount $counter, string $amount, string $date, string $description, string $key, ?string $userId): TreasuryBankMovement
    {
        return TreasuryBankMovement::query()->create([
            'tenant_id' => $tenantId,
            'company_id' => $account->company_id,
            'movement_type' => $type,
            'bank_account_id' => $account->id,
            'counter_bank_account_id' => $counter?->id,
            'amount' => $amount,
            'currency' => $account->currency,
            'movement_date' => $date,
            'description' => mb_substr($description, 0, 255),
            'idempotency_key' => $key,
            'created_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
        ]);
    }

    /**
     * Moves the balance of a locked bank account and keeps the movement in its transactions, so balances per date can
     * be rebuilt.
     */
    private function moveBalance(BankAccount $account, string $delta, string $date, string $description, string $entryId): void
    {
        $account->update([
            'current_balance' => bcadd((string) $account->current_balance, $delta, 2),
            'available_balance' => bcadd((string) $account->available_balance, $delta, 2),
        ]);

        BankTransaction::query()->create([
            'tenant_id' => $account->tenant_id,
            'company_id' => $account->company_id,
            'bank_account_id' => $account->id,
            'booking_date' => $date,
            'value_date' => $date,
            'amount' => $delta,
            'currency' => $account->currency,
            'description' => $description,
            'reference' => $entryId,
            'transaction_type' => 'treasury',
            'gl_journal_entry_id' => $entryId,
            'status' => 'matched',
        ]);
    }

    private function finish(TreasuryBankMovement $movement, string $entryId): TreasuryBankMovement
    {
        $movement->update(['journal_entry_id' => $entryId]);

        $this->dispatcher->publishAndDispatch(new BankMovementPostedEvent(
            movementId: (string) $movement->id,
            movementType: (string) $movement->movement_type,
            bankAccountId: (string) $movement->bank_account_id,
            counterBankAccountId: $movement->counter_bank_account_id,
            amount: (string) $movement->amount,
            currency: (string) $movement->currency,
            movementDate: $movement->movement_date->toDateString(),
            journalEntryId: $entryId,
            tenantId: (string) $movement->tenant_id,
            companyId: (string) ($movement->company_id ?? ''),
        ));

        return $movement;
    }

    /**
     * @throws TreasuryOperationException when the amount is not positive
     */
    private function positive(mixed $amount): string
    {
        $value = bcadd((string) $amount, '0', 2);

        if (bccomp($value, '0', 2) !== 1) {
            throw new TreasuryOperationException('The amount must be positive.', 'amount');
        }

        return $value;
    }

    /**
     * @return array{account: ChartOfAccount, debit: string, credit: string, description: string, currency: string}
     */
    private function line(ChartOfAccount $account, string $debit, string $credit, string $description, string $currency): array
    {
        return ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'description' => $description, 'currency' => $currency];
    }
}
