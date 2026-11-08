<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Treasury\Contracts\LiquidityManagementServiceInterface;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Events\TreasuryAllocationExecutedEvent;
use Modules\Treasury\Exceptions\TreasuryOperationException;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\CashPool;
use Modules\Treasury\Models\IntercompanyFunding;
use Modules\Treasury\Models\WorkingCapitalMetric;

final readonly class LiquidityManagementService implements LiquidityManagementServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher,
        private TreasuryPositionServiceInterface $positionService,
        private TreasuryKpiService $kpis,
        private TreasuryLedgerPoster $poster,
        private TreasuryLedgerAccounts $ledgerAccounts,
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

    /**
     * Stores the working capital metrics of a day from the real figures of the tenant (TreasuryKpiService over the
     * trailing twelve months). Inventory days are not derived by treasury: they are stored as zero and the cash
     * conversion cycle is DSO - DPO. A ratio without a denominator is stored as zero.
     */
    public function calculateWorkingCapital(string $tenantId, ?string $metricDate = null, ?string $companyId = null): WorkingCapitalMetric
    {
        $date = $metricDate ?? now()->toDateString();
        $kpis = $this->kpis->kpis($tenantId, CarbonImmutable::parse($date), 365, null);
        $arTotal = (string) $kpis['open_receivables'];
        $apTotal = (string) $kpis['open_payables'];
        $revenue = (string) $kpis['sales'];
        $cogs = (string) $kpis['purchases'];
        $inventoryValuation = '0';
        $dso = (float) ($kpis['dso_days'] ?? 0);
        $dpo = (float) ($kpis['dpo_days'] ?? 0);
        $dio = 0.0;
        $ccc = (float) ($kpis['cash_conversion_cycle_days'] ?? 0);
        $currentAssets = bcadd((string) $kpis['cash'], $arTotal, 2);
        $currentRatio = bccomp($apTotal, '0', 2) === 1 ? min(999.999, (float) bcdiv($currentAssets, $apTotal, 3)) : 0.0;
        $quickRatio = $currentRatio;

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
        ?string $userId = null,
        bool $post = true,
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
            $cashPoolId,
            $userId,
            $post
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

            $journalEntryId = $post ? $this->postTransfer($source, $dest, $funding, $amount, $transferDate, $userId) : null;

            $event = new TreasuryAllocationExecutedEvent(
                allocationId: (string) $funding->id,
                sourceBankAccountId: (string) $source->id,
                destinationBankAccountId: (string) $dest->id,
                amount: $amount,
                currency: (string) ($source->currency ?? 'EUR'),
                journalEntryId: $journalEntryId,
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

    /**
     * Posts a transfer on the ledger accounts of both bank accounts (Dr destination / Cr source) when both are linked to
     * the chart of accounts. Bank accounts outside the ledger only move their treasury balances; a transfer between a
     * linked and an unlinked account is refused, because it would leave the ledger out of step with the bank.
     *
     * @throws TreasuryOperationException
     */
    private function postTransfer(BankAccount $source, BankAccount $destination, IntercompanyFunding $funding, float $amount, string $date, ?string $userId): ?string
    {
        if ($source->gl_account_id === null && $destination->gl_account_id === null) {
            return null;
        }

        if ($source->currency !== $destination->currency) {
            throw new TreasuryOperationException('An internal transfer moves money between accounts in the same currency.', 'destination_bank_account_id');
        }

        $value = number_format($amount, 2, '.', '');
        $label = "Transfer {$source->iban} > {$destination->iban}";
        $currency = (string) $source->currency;

        return (string) $this->poster->post((string) $source->tenant_id, 'treasury_transfer', (string) $funding->id, $date, $label, $label, [
            ['account' => $this->ledgerAccounts->bank($destination, 'destination_bank_account_id'), 'debit' => $value, 'credit' => '0', 'description' => $label, 'currency' => $currency],
            ['account' => $this->ledgerAccounts->bank($source, 'source_bank_account_id'), 'debit' => '0', 'credit' => $value, 'description' => $label, 'currency' => $currency],
        ], true, $userId)->id;
    }
}
