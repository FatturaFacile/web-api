<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\InventoryLedgerGateway;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryCostingReadyEvent;
use Modules\Inventory\Events\InventoryRevaluationEvent;
use Modules\Inventory\Events\InventoryRevaluedEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;
use Modules\Inventory\Events\LandedCostAppliedEvent;
use Modules\Inventory\Events\PurchasePriceVarianceRecordedEvent;

final class InventoryAccountingListener
{
    /**
     * Default chart-of-accounts entries per inventory valuation leg role
     * (FatturaFacile/FatturaFacile#144): [code, name, type, normal balance].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const array VALUATION_ROLE_ACCOUNTS = [
        'inventory' => ['1200', 'Merchandise Inventory Asset', 'asset', 'debit'],
        'cogs' => ['5000', 'Cost of Goods Sold (COGS)', 'cogs', 'debit'],
        'ppv' => ['5110', 'Purchase Price Variance', 'expense', 'debit'],
        'landed_cost_clearing' => ['2150', 'Landed Cost Clearing', 'liability', 'credit'],
        'variance' => ['5100', 'Inventory Variance & Shrinkage', 'expense', 'debit'],
    ];

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly InventoryLedgerGateway $ledgerGateway
    ) {}

    /**
     * Landed cost application (Dr inventory for units on hand, Dr COGS for units sold, Dr PPV for
     * standard-cost items, Cr landed-cost clearing) and its reversal (mirror legs).
     */
    public function handleLandedCostApplied(LandedCostAppliedEvent $event): void
    {
        $this->postValuationJournal(
            sourceType: $event->sourceDocumentType(),
            sourceId: $event->landedCostDocumentId,
            tenantId: $event->tenantId,
            companyId: $event->companyId,
            postingDate: $event->postingDate,
            description: ($event->isReversal ? 'Landed cost reversal ' : 'Landed cost ').$event->documentNumber,
            legs: $event->legs,
            journalType: $event->isReversal ? 'reversing' : 'standard',
            entryPrefix: $event->isReversal ? 'INV-LCR-' : 'INV-LC-',
            strict: true
        );
    }

    /**
     * Revaluation after a valuation method / standard cost change (inventory vs COGS).
     */
    public function handleInventoryRevalued(InventoryRevaluedEvent $event): void
    {
        $this->postValuationJournal(
            sourceType: $event->sourceDocumentType(),
            sourceId: $event->revaluationId,
            tenantId: $event->tenantId,
            companyId: $event->companyId,
            postingDate: $event->postingDate,
            description: $event->description,
            legs: $event->legs,
            journalType: 'standard',
            entryPrefix: 'INV-RVL-',
            strict: true
        );
    }

    /**
     * Purchase price variance on standard-cost receipts (Dr/Cr PPV vs inventory). Skipped, like the
     * other operational inventory postings, when no open period exists.
     */
    public function handlePurchasePriceVariance(PurchasePriceVarianceRecordedEvent $event): void
    {
        $this->postValuationJournal(
            sourceType: $event->sourceDocumentType(),
            sourceId: $event->movementId,
            tenantId: $event->tenantId,
            companyId: $event->companyId,
            postingDate: $event->postingDate,
            description: "Purchase price variance (movement {$event->movementId})",
            legs: $event->legs,
            journalType: 'standard',
            entryPrefix: 'INV-PPV-',
            strict: false
        );
    }

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

    /**
     * @param  list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>  $legs
     */
    private function postValuationJournal(
        string $sourceType,
        string $sourceId,
        string $tenantId,
        string $companyId,
        string $postingDate,
        string $description,
        array $legs,
        string $journalType,
        string $entryPrefix,
        bool $strict
    ): void {
        if ($tenantId === '' || $legs === []) {
            return;
        }

        // Idempotent per source document (outbox relays may redeliver the event).
        $alreadyPosted = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId)
            ->whereIn('status', ['posted', 'reversed'])
            ->exists();
        if ($alreadyPosted) {
            return;
        }

        $period = $this->ledgerGateway->resolvePostingPeriod($tenantId, $companyId !== '' ? $companyId : null, $postingDate);
        if ($period === null) {
            if ($strict) {
                throw new InvalidArgumentException("No open fiscal period available for inventory valuation posting dated {$postingDate}.");
            }

            return;
        }

        $lines = [];
        foreach ($legs as $leg) {
            $debit = (float) $leg['debit'];
            $credit = (float) $leg['credit'];
            if ($debit <= 0.0 && $credit <= 0.0) {
                continue;
            }

            $lines[] = [
                'account_id' => $this->resolveRoleAccount($tenantId, $leg['role'], $leg['account_id'])->id,
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'description' => $leg['description'],
            ];
        }

        if ($lines === []) {
            return;
        }

        $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $companyId !== '' ? $companyId : null,
            'fiscal_period_id' => $period['fiscal_period_id'],
            'entry_number' => $entryPrefix.strtoupper(Str::random(8)),
            'posting_date' => $period['posting_date'],
            'document_date' => $postingDate,
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'journal_type' => $journalType,
            'description' => $description,
            'lines' => $lines,
        ]);
    }

    private function resolveRoleAccount(string $tenantId, string $role, ?string $accountId): ChartOfAccount
    {
        if ($accountId !== null && $accountId !== '') {
            $override = ChartOfAccount::query()->where('tenant_id', $tenantId)->whereKey($accountId)->first();
            if ($override !== null) {
                return $override;
            }
        }

        [$code, $name, $type, $normalBalance] = self::VALUATION_ROLE_ACCOUNTS[$role] ?? self::VALUATION_ROLE_ACCOUNTS['variance'];

        return $this->resolveAccount($tenantId, $code, $name, $type, $normalBalance);
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
