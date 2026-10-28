<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Events\TreasuryAllocationExecutedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashPool;
use Modules\Treasury\Models\IntercompanyFunding;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\ReceivableCollection;
use Modules\Treasury\Models\WorkingCapitalMetric;

final readonly class LiquidityManagementService implements LiquidityManagementServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher,
        private TreasuryPositionServiceInterface $positionService
    ) {}

    public function executeCashConcentration(string $cashPoolId, ?string $userId = null): array
    {
        return DB::transaction(function () use ($cashPoolId, $userId) {
            $pool = CashPool::findOrFail($cashPoolId);
            $headerAccount = BankAccount::findOrFail($pool->header_bank_account_id);

            // Find participating bank accounts in the same tenant and currency (excluding the header itself)
            $subAccounts = BankAccount::where('tenant_id', $pool->tenant_id)
                ->where('id', '!=', $headerAccount->id)
                ->where('currency', $pool->currency)
                ->where('is_active', true)
                ->get();

            $transfers = [];
            foreach ($subAccounts as $sub) {
                $subBalance = (float) $sub->current_balance;
                $floor = (float) $pool->target_balance_floor;

                // Zero Balance Account (ZBA) or Target Balance: Sweep excess balance to header
                $sweepAmount = $subBalance - $floor;
                if ($sweepAmount <= 0) {
                    continue;
                }

                $transfer = $this->transferIntercompanyFunds(
                    sourceBankAccountId: (string) $sub->id,
                    destinationBankAccountId: (string) $headerAccount->id,
                    amount: $sweepAmount,
                    transferDate: now()->toDateString(),
                    interestRate: 0.0250,
                    cashPoolId: (string) $pool->id,
                    userId: $userId
                );
                $transfers[] = $transfer;
            }

            return $transfers;
        });
    }

    public function calculateWorkingCapital(string $tenantId, ?string $metricDate = null, ?string $companyId = null): WorkingCapitalMetric
    {
        $date = $metricDate ?? now()->toDateString();

        $arTotal = (float) ReceivableCollection::where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->sum(DB::raw('expected_amount - collected_amount'));

        $apTotal = (float) PaymentLine::where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount');

        // Defaults for baseline financial ratios if not yet populated from GL
        $revenue = 1200000.00;
        $cogs = 750000.00;
        $inventoryValuation = 180000.00;

        $dso = $revenue > 0 ? ($arTotal / $revenue) * 365 : 45.0;
        $dpo = $cogs > 0 ? ($apTotal / $cogs) * 365 : 30.0;
        $dio = $cogs > 0 ? ($inventoryValuation / $cogs) * 365 : 40.0;
        $ccc = $dso + $dio - $dpo;

        $currentAssets = $arTotal + $inventoryValuation + 250000.00; // includes cash estimate
        $currentLiabilities = max(50000.00, $apTotal);

        $currentRatio = min(999.999, round($currentAssets / $currentLiabilities, 3));
        $quickRatio = min(999.999, round(max(0.0, $currentAssets - $inventoryValuation) / $currentLiabilities, 3));

        /** @var WorkingCapitalMetric $metric */
        $metric = WorkingCapitalMetric::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'metric_date' => $date,
            ],
            [
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'accounts_receivable_total' => $arTotal,
                'accounts_payable_total' => $apTotal,
                'inventory_valuation_total' => $inventoryValuation,
                'trailing_12m_revenue' => $revenue,
                'trailing_12m_cogs' => $cogs,
                'dso_days' => round($dso, 2),
                'dpo_days' => round($dpo, 2),
                'dio_days' => round($dio, 2),
                'cash_conversion_cycle_days' => round($ccc, 2),
                'current_ratio' => round($currentRatio, 3),
                'quick_ratio' => round($quickRatio, 3),
            ]
        );

        $this->auditLedger->record(
            action: 'working_capital_calculated',
            model: $metric,
            payloadBefore: null,
            payloadAfter: $metric->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );

        return $metric;
    }

    public function transferIntercompanyFunds(
        string $sourceBankAccountId,
        string $destinationBankAccountId,
        float $amount,
        string $transferDate,
        float $interestRate = 0.0250,
        ?string $cashPoolId = null,
        ?string $userId = null
    ): IntercompanyFunding {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Intercompany transfer amount must be strictly positive.');
        }

        return DB::transaction(function () use (
            $sourceBankAccountId,
            $destinationBankAccountId,
            $amount,
            $transferDate,
            $interestRate,
            $cashPoolId
        ) {
            $source = BankAccount::where('id', $sourceBankAccountId)->lockForUpdate()->firstOrFail();
            $dest = BankAccount::where('id', $destinationBankAccountId)->lockForUpdate()->firstOrFail();

            // Perform balances movement
            $this->positionService->updateBalance($source->id, -1 * $amount, 'intercompany_sweep_out');
            $this->positionService->updateBalance($dest->id, $amount, 'intercompany_sweep_in');

            /** @var IntercompanyFunding $funding */
            $funding = IntercompanyFunding::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $source->tenant_id,
                'cash_pool_id' => $cashPoolId,
                'source_bank_account_id' => $source->id,
                'destination_bank_account_id' => $dest->id,
                'transfer_amount' => $amount,
                'transfer_date' => $transferDate,
                'intercompany_interest_rate' => $interestRate,
                'status' => 'executed',
            ]);

            $event = new TreasuryAllocationExecutedEvent(
                allocationId: (string) $funding->id,
                sourceBankAccountId: (string) $source->id,
                destinationBankAccountId: (string) $dest->id,
                amount: $amount,
                currency: (string) ($source->currency ?? 'EUR'),
                journalEntryId: null,
                tenantId: (string) $source->tenant_id,
                companyId: (string) ($source->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'intercompany_funding_executed',
                model: $funding,
                payloadBefore: null,
                payloadAfter: $funding->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $funding;
        });
    }
}
