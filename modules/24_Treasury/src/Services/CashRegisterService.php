<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Treasury\Events\CashRegisterClosedEvent;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Models\CashClosing;
use Modules\Treasury\Models\CashMovement;
use Modules\Treasury\Models\CashRegister;

/**
 * The cash book (prima nota cassa). Every receipt or payment, in cash or by cheque, is posted to the ledger against a
 * counter account of the chart (Dr cash / Cr counter for a receipt, the opposite for a payment) and moves the register
 * balance, with the register locked. A register cannot go below zero. The daily closing compares the balance with the
 * cash counted, posts any difference on `banking.ledger_defaults.cash_difference_account_code` and freezes the day:
 * no movement can be dated on or before a closed day.
 */
final readonly class CashRegisterService
{
    public function __construct(
        private TreasuryLedgerPoster $poster,
        private TreasuryLedgerAccounts $accounts,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Opens a register on a ledger account of the chart.
     *
     * @param  array{code: string, name: string, currency: string, gl_account_id: string, opening_balance?: numeric-string|float|int|null}  $data
     */
    public function create(string $tenantId, array $data): CashRegister
    {
        $this->accounts->byId($tenantId, $data['gl_account_id'], 'gl_account_id');
        $opening = bcadd((string) ($data['opening_balance'] ?? '0'), '0', 2);

        return CashRegister::query()->create([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'currency' => strtoupper($data['currency']),
            'gl_account_id' => $data['gl_account_id'],
            'opening_balance' => $opening,
            'current_balance' => $opening,
            'is_active' => true,
        ]);
    }

    /**
     * Records and posts a receipt or payment. A repeated idempotency key returns the movement already recorded.
     *
     * @param  array{movement_date: string, direction: string, payment_method?: ?string, amount: numeric-string|float|int, counter_account_id: string, description: string, reference?: ?string, idempotency_key: string}  $data
     *
     * @throws TreasuryOperationException
     */
    public function recordMovement(string $tenantId, string $registerId, array $data, ?string $userId): CashMovement
    {
        try {
            return DB::transaction(function () use ($tenantId, $registerId, $data, $userId): CashMovement {
                $existing = CashMovement::query()->where('tenant_id', $tenantId)->where('idempotency_key', $data['idempotency_key'])->first();

                if ($existing !== null) {
                    return $existing;
                }

                $register = CashRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
                $this->assertOpenDay($register, $data['movement_date']);
                $amount = bcadd((string) $data['amount'], '0', 2);
                $isReceipt = $data['direction'] === CashMovement::In;
                $balance = bcadd((string) $register->current_balance, $isReceipt ? $amount : bcmul($amount, '-1', 2), 2);

                if (bccomp($balance, '0', 2) === -1) {
                    throw new TreasuryOperationException('The cash register does not hold enough cash for this payment.', 'amount');
                }

                $cash = $this->accounts->byId($tenantId, (string) $register->gl_account_id, 'cash_register');
                $counter = $this->accounts->byId($tenantId, $data['counter_account_id'], 'counter_account_id');

                $movement = CashMovement::query()->create([
                    'tenant_id' => $tenantId,
                    'cash_register_id' => $register->id,
                    'movement_date' => $data['movement_date'],
                    'direction' => $data['direction'],
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'amount' => $amount,
                    'counter_account_id' => $counter->id,
                    'description' => $data['description'],
                    'reference' => $data['reference'] ?? null,
                    'idempotency_key' => $data['idempotency_key'],
                    'created_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
                ]);

                $line = static fn ($account, string $debit, string $credit): array => ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'description' => $data['description'], 'currency' => (string) $register->currency];
                $entry = $this->poster->post($tenantId, 'treasury_cash_movement', (string) $movement->id, $data['movement_date'], (string) ($data['reference'] ?? $register->code), "Cash {$register->code}: {$data['description']}", $isReceipt
                    ? [$line($cash, $amount, '0'), $line($counter, '0', $amount)]
                    : [$line($counter, $amount, '0'), $line($cash, '0', $amount)], true, $userId);

                $movement->update(['journal_entry_id' => $entry->id]);
                $register->update(['current_balance' => $balance]);

                return $movement;
            });
        } catch (UniqueConstraintViolationException $e) {
            return CashMovement::query()->where('tenant_id', $tenantId)->where('idempotency_key', $data['idempotency_key'])->first() ?? throw $e;
        }
    }

    /**
     * Closes a day: the movements of the day give the expected balance, the counted cash is compared with it and the
     * difference is posted (Dr cash / Cr difference account for a surplus, the opposite for a shortage).
     *
     * @throws TreasuryOperationException
     */
    public function close(string $tenantId, string $registerId, string $closingDate, string $countedBalance, ?string $notes, ?string $userId): CashClosing
    {
        return DB::transaction(function () use ($tenantId, $registerId, $closingDate, $countedBalance, $notes, $userId): CashClosing {
            $register = CashRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
            $day = CarbonImmutable::parse($closingDate)->toDateString();
            $this->assertOpenDay($register, $day);

            if (CashMovement::query()->where('cash_register_id', $register->id)->whereDate('movement_date', '>', $day)->exists()) {
                throw new TreasuryOperationException('Movements dated after this day exist: close the days in order.', 'closing_date');
            }

            $totalIn = $this->sumOf($register, $day, CashMovement::In);
            $totalOut = $this->sumOf($register, $day, CashMovement::Out);
            $expected = bcadd((string) $register->current_balance, '0', 2);
            $opening = bcsub(bcadd($expected, $totalOut, 2), $totalIn, 2);
            $counted = bcadd($countedBalance, '0', 2);
            $difference = bcsub($counted, $expected, 2);
            $entryId = null;

            $closing = CashClosing::query()->create([
                'tenant_id' => $tenantId,
                'cash_register_id' => $register->id,
                'closing_date' => $day,
                'opening_balance' => $opening,
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'expected_balance' => $expected,
                'counted_balance' => $counted,
                'difference' => $difference,
                'notes' => $notes,
                'closed_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
            ]);

            if (bccomp($difference, '0', 2) !== 0) {
                $differenceAccount = $this->accounts->configured($tenantId, 'cash_difference_account_code', 'counted_balance');
                $cash = $this->accounts->byId($tenantId, (string) $register->gl_account_id, 'cash_register');
                $absolute = ltrim($difference, '-');
                $isSurplus = bccomp($difference, '0', 2) === 1;
                $label = "Cash difference {$register->code} {$day}";
                $line = static fn ($account, string $debit, string $credit): array => ['account' => $account, 'debit' => $debit, 'credit' => $credit, 'description' => $label, 'currency' => (string) $register->currency];
                $entryId = (string) $this->poster->post($tenantId, 'treasury_cash_difference', (string) $closing->id, $day, (string) $register->code, $label, $isSurplus
                    ? [$line($cash, $absolute, '0'), $line($differenceAccount, '0', $absolute)]
                    : [$line($differenceAccount, $absolute, '0'), $line($cash, '0', $absolute)], true, $userId)->id;
                $closing->update(['difference_journal_entry_id' => $entryId]);
            }

            $register->update(['current_balance' => $counted, 'last_closed_date' => $day]);

            $this->dispatcher->publishAndDispatch(new CashRegisterClosedEvent(
                cashRegisterId: (string) $register->id,
                closingId: (string) $closing->id,
                closingDate: $day,
                expectedBalance: $expected,
                countedBalance: $counted,
                difference: $difference,
                tenantId: $tenantId,
                companyId: (string) ($register->company_id ?? ''),
            ));

            return $closing;
        });
    }

    /**
     * @throws TreasuryOperationException when the day is closed or the register inactive
     */
    private function assertOpenDay(CashRegister $register, string $date): void
    {
        if (! $register->is_active) {
            throw new TreasuryOperationException('The cash register is not active.', 'cash_register');
        }

        if ($register->last_closed_date !== null && CarbonImmutable::parse($date)->lessThanOrEqualTo($register->last_closed_date)) {
            throw new TreasuryOperationException("The cash register is closed up to {$register->last_closed_date->toDateString()}.", 'movement_date');
        }
    }

    private function sumOf(CashRegister $register, string $day, string $direction): string
    {
        $sum = CashMovement::query()->where('cash_register_id', $register->id)->whereDate('movement_date', $day)->where('direction', $direction)->sum('amount');

        return bcadd((string) $sum, '0', 2);
    }
}
