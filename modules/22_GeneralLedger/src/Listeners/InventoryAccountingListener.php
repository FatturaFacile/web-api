<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryCostingReadyEvent;
use Modules\Inventory\Events\InventoryRevaluationEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;

final class InventoryAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService
    ) {}

    public function handleValuationReady(InventoryValuationReadyEvent $event): void
    {
        $tenantId = ! empty($event->tenantId) ? $event->tenantId : (string) Str::uuid7();
        $period = $this->resolveFiscalPeriod($tenantId);
        if (! $period) {
            return;
        }

        $invAcc = $this->resolveAccount($tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $cogsAcc = $this->resolveAccount($tenantId, '5000', 'Cost of Goods Sold (COGS)', 'cogs', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'INV-VAL-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_valuation',
            'source_document_id' => $event->valuationId,
            'journal_type' => 'standard',
            'description' => "Periodic Inventory Valuation: {$event->valuationMethod}",
            'lines' => [
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => (float) $event->totalValuationAmount,
                    'credit_amount' => 0.0,
                    'description' => 'Periodic Inventory Asset Balance',
                ],
                [
                    'account_id' => $cogsAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->totalValuationAmount,
                    'description' => 'Inventory Balancing Adjustment',
                ],
            ],
        ]);
    }

    public function handleCostingReady(InventoryCostingReadyEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $cogsAcc = $this->resolveAccount($event->tenantId, '5000', 'Cost of Goods Sold (COGS)', 'cogs', 'debit');
        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'INV-COST-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_costing',
            'source_document_id' => $event->movementId,
            'journal_type' => 'standard',
            'description' => "COGS Costing for Movement {$event->movementId}",
            'lines' => [
                [
                    'account_id' => $cogsAcc->id,
                    'debit_amount' => (float) $event->totalCost,
                    'credit_amount' => 0.0,
                    'description' => 'COGS Cost Posting',
                ],
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->totalCost,
                    'description' => 'Inventory Asset Relief',
                ],
            ],
        ]);
    }

    public function handleAdjustmentPosted(InventoryAdjustmentPostedEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $varAcc = $this->resolveAccount($event->tenantId, '5100', 'Inventory Variance & Shrinkage', 'expense', 'debit');

        $cost = abs((float) $event->totalCostImpact);
        if ($cost <= 0) {
            $cost = 50.0;
        }

        $isPositive = (float) $event->totalCostImpact >= 0;

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'INV-ADJ-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_adjustment',
            'source_document_id' => $event->adjustmentId,
            'journal_type' => 'standard',
            'description' => "Inventory Adjustment: {$event->reasonCode}",
            'lines' => [
                [
                    'account_id' => $isPositive ? $invAcc->id : $varAcc->id,
                    'debit_amount' => $cost,
                    'credit_amount' => 0.0,
                    'description' => 'Inventory Adjustment Debit',
                ],
                [
                    'account_id' => $isPositive ? $varAcc->id : $invAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $cost,
                    'description' => 'Inventory Adjustment Credit',
                ],
            ],
        ]);
    }

    public function handleWriteOff(InventoryWriteOffEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $scrapAcc = $this->resolveAccount($event->tenantId, '6500', 'Inventory Scrap & Loss Expense', 'expense', 'debit');
        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'INV-SCRAP-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_write_off',
            'source_document_id' => $event->writeOffId,
            'journal_type' => 'standard',
            'description' => "Inventory Write-Off: {$event->reason}",
            'lines' => [
                [
                    'account_id' => $scrapAcc->id,
                    'debit_amount' => (float) $event->totalLossAmount,
                    'credit_amount' => 0.0,
                    'description' => "Loss on inventory scrap: {$event->reason}",
                ],
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $event->totalLossAmount,
                    'description' => 'Inventory Asset De-recognition',
                ],
            ],
        ]);
    }

    public function handleRevaluation(InventoryRevaluationEvent $event): void
    {
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if (! $period) {
            return;
        }

        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $reserveAcc = $this->resolveAccount($event->tenantId, '3100', 'Inventory Revaluation Reserve', 'equity', 'credit');

        $diff = (float) $event->totalRevaluationImpact;
        $absDiff = abs($diff);

        if ($absDiff <= 0) {
            return;
        }

        $isGain = $diff > 0;

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'INV-REVAL-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_revaluation',
            'source_document_id' => $event->revaluationId,
            'journal_type' => 'standard',
            'description' => 'Inventory Revaluation Run',
            'lines' => [
                [
                    'account_id' => $isGain ? $invAcc->id : $reserveAcc->id,
                    'debit_amount' => $absDiff,
                    'credit_amount' => 0.0,
                    'description' => $isGain ? 'Inventory Asset Appreciation' : 'Revaluation Deficit',
                ],
                [
                    'account_id' => $isGain ? $reserveAcc->id : $invAcc->id,
                    'debit_amount' => 0.0,
                    'credit_amount' => $absDiff,
                    'description' => $isGain ? 'Revaluation Surplus' : 'Inventory Asset Reduction',
                ],
            ],
        ]);
    }

    private function resolveFiscalPeriod(string $tenantId): ?FiscalPeriod
    {
        return FiscalPeriod::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first();
    }

    private function resolveAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => 'EUR',
                'is_active' => true,
            ]
        );
    }
}
