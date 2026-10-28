<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Events\CashPositionUpdatedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountBalance;
use Modules\Treasury\Models\BankGuarantee;

final readonly class TreasuryPositionService implements TreasuryPositionServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher
    ) {}

    public function getIntradayPosition(string $tenantId, ?string $companyId = null, ?string $currency = null): array
    {
        $query = BankAccount::where('tenant_id', $tenantId)
            ->where('is_active', true);

        if (! empty($companyId)) {
            $query->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            });
        }

        if (! empty($currency)) {
            $query->where('currency', strtoupper($currency));
        }

        $accounts = $query->get();

        $byCurrency = [];
        $totalLedger = 0.0;
        $totalAvailable = 0.0;
        $totalOverdraft = 0.0;

        foreach ($accounts as $acc) {
            $curr = $acc->currency ?? 'EUR';
            if (! isset($byCurrency[$curr])) {
                $byCurrency[$curr] = [
                    'currency' => $curr,
                    'ledger_balance' => 0.0,
                    'available_balance' => 0.0,
                    'overdraft_limit' => 0.0,
                    'account_count' => 0,
                    'accounts' => [],
                ];
            }

            $currentBal = (float) $acc->current_balance;
            $availBal = (float) $acc->available_balance;
            $overdraft = (float) $acc->overdraft_limit;

            $byCurrency[$curr]['ledger_balance'] += $currentBal;
            $byCurrency[$curr]['available_balance'] += $availBal;
            $byCurrency[$curr]['overdraft_limit'] += $overdraft;
            $byCurrency[$curr]['account_count']++;
            $byCurrency[$curr]['accounts'][] = [
                'id' => $acc->id,
                'bank_name' => $acc->bank_name,
                'iban' => $acc->iban,
                'account_type' => $acc->account_type,
                'current_balance' => $currentBal,
                'available_balance' => $availBal,
                'overdraft_limit' => $overdraft,
            ];

            if ($curr === 'EUR') {
                $totalLedger += $currentBal;
                $totalAvailable += $availBal;
                $totalOverdraft += $overdraft;
            }
        }

        return [
            'as_of' => now()->toIso8601String(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'base_currency' => 'EUR',
            'total_ledger_balance_base' => round($totalLedger, 2),
            'total_available_balance_base' => round($totalAvailable, 2),
            'total_overdraft_facility_base' => round($totalOverdraft, 2),
            'positions_by_currency' => array_values($byCurrency),
        ];
    }

    public function updateBalance(string $bankAccountId, float $deltaAmount, string $source = 'transaction'): BankAccount
    {
        return DB::transaction(function () use ($bankAccountId, $deltaAmount) {
            /** @var BankAccount $account */
            $account = BankAccount::where('id', $bankAccountId)->lockForUpdate()->firstOrFail();

            $before = $account->toArray();
            $newCurrent = (float) $account->current_balance + $deltaAmount;
            $newAvailable = (float) $account->available_balance + $deltaAmount;

            $account->update([
                'current_balance' => $newCurrent,
                'available_balance' => $newAvailable,
            ]);

            $this->auditLedger->record(
                action: 'balance_updated',
                model: $account,
                payloadBefore: $before,
                payloadAfter: $account->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            $event = new CashPositionUpdatedEvent(
                bankAccountId: (string) $account->id,
                currentBalance: $newCurrent,
                availableBalance: $newAvailable,
                currency: (string) $account->currency,
                tenantId: (string) $account->tenant_id,
                companyId: (string) ($account->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            return $account;
        });
    }

    public function snapshotDailyBalances(string $tenantId, ?string $date = null): array
    {
        $snapDate = $date ?? now()->toDateString();
        $accounts = BankAccount::where('tenant_id', $tenantId)->where('is_active', true)->get();
        $snapshots = [];

        foreach ($accounts as $acc) {
            $snap = BankAccountBalance::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'bank_account_id' => $acc->id,
                    'balance_date' => $snapDate,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'ledger_balance' => (float) $acc->current_balance,
                    'available_balance' => (float) $acc->available_balance,
                    'currency' => $acc->currency ?? 'EUR',
                    'source' => 'eod_calculation',
                ]
            );
            $snapshots[] = $snap;
        }

        return $snapshots;
    }

    public function calculateLiquidityBuffer(string $tenantId, ?string $companyId = null): array
    {
        $accounts = BankAccount::where('tenant_id', $tenantId)->where('is_active', true)->get();

        $totalCash = 0.0;
        $overdraftUtilized = 0.0;

        foreach ($accounts as $acc) {
            $bal = (float) $acc->current_balance;
            if ($bal >= 0) {
                $totalCash += $bal;
            } else {
                $overdraftUtilized += abs($bal);
            }
        }

        // Calculate blocked cash from active guarantees
        $blockedCash = (float) BankGuarantee::where('tenant_id', $tenantId)
            ->where('status', 'issued')
            ->sum('collateral_cash_blocked');

        $availableCash = max(0.0, $totalCash - $blockedCash);

        return [
            'total_cash' => round($totalCash, 2),
            'available_cash' => round($availableCash, 2),
            'blocked_cash' => round($blockedCash, 2),
            'overdraft_utilized' => round($overdraftUtilized, 2),
            'currency' => 'EUR',
        ];
    }
}
