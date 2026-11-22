<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\InventoryLedgerGateway;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\LedgerAmount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Events\InventoryCostingReadyEvent;
use Modules\Inventory\Events\InventoryRevaluationEvent;
use Modules\Inventory\Events\InventoryRevaluedEvent;
use Modules\Inventory\Events\InventoryValuationReadyEvent;
use Modules\Inventory\Events\InventoryWriteOffEvent;
use Modules\Inventory\Events\LandedCostAppliedEvent;
use Modules\Inventory\Events\PurchasePriceVarianceRecordedEvent;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountPolicy;

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
        private readonly FiscalPeriodResolver $periods,
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
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($tenantId, $postingDate);

        $invAcc = $this->resolveAccount($tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $cogsAcc = $this->resolveAccount($tenantId, '5000', 'Cost of Goods Sold (COGS)', 'cogs', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'INV-VAL-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_valuation',
            'source_document_id' => $event->valuationId,
            'journal_type' => 'standard',
            'description' => "Periodic Inventory Valuation: {$event->valuationMethod}",
            'lines' => [
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => LedgerAmount::of($event->totalValuationAmount),
                    'credit_amount' => '0',
                    'description' => 'Periodic Inventory Asset Balance',
                ],
                [
                    'account_id' => $cogsAcc->id,
                    'debit_amount' => '0',
                    'credit_amount' => LedgerAmount::of($event->totalValuationAmount),
                    'description' => 'Inventory Balancing Adjustment',
                ],
            ],
        ]);
    }

    public function handleCostingReady(InventoryCostingReadyEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $cogsAcc = $this->resolveAccount($event->tenantId, '5000', 'Cost of Goods Sold (COGS)', 'cogs', 'debit');
        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'INV-COST-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_costing',
            'source_document_id' => $event->movementId,
            'journal_type' => 'standard',
            'description' => "COGS Costing for Movement {$event->movementId}",
            'lines' => [
                [
                    'account_id' => $cogsAcc->id,
                    'debit_amount' => LedgerAmount::of($event->totalCost),
                    'credit_amount' => '0',
                    'description' => 'COGS Cost Posting',
                ],
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => '0',
                    'credit_amount' => LedgerAmount::of($event->totalCost),
                    'description' => 'Inventory Asset Relief',
                ],
            ],
        ]);
    }

    /**
     * Books the cost impact of a posted inventory adjustment. Idempotent: an adjustment is booked once, a replay of the
     * event only restores the link between the adjustment and its journal entry.
     *
     * Plain adjustments debit/credit inventory against the variance account (5100). The differences of a physical count
     * (`cycle_count_variance`) use the accounts of the tenant's InventoryCountPolicy: a gain debits inventory and
     * credits the inventory gain account (sopravvenienza attiva), a loss debits the inventory loss account
     * (insussistenza passiva / differenza inventariale) and credits inventory.
     */
    public function handleAdjustmentPosted(InventoryAdjustmentPostedEvent $event): void
    {
        $existing = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('source_document_type', 'inventory_adjustment')
            ->where('source_document_id', $event->adjustmentId)
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            $this->linkAdjustment($event->adjustmentId, (string) $existing->id);

            return;
        }

        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $isCount = $event->reasonCode === 'cycle_count_variance';
        $policy = InventoryCountPolicy::forTenant($event->tenantId);

        $invAcc = $this->resolveAccount($event->tenantId, $isCount ? $policy->inventory_account_code : '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $lossAcc = $this->resolveAccount($event->tenantId, $isCount ? $policy->loss_account_code : '5100', 'Inventory Variance & Shrinkage', 'expense', 'debit');
        $gainAcc = $isCount
            ? $this->resolveAccount($event->tenantId, $policy->gain_account_code, 'Inventory Gain (Sopravvenienze Attive)', 'revenue', 'credit')
            : $lossAcc;

        // Nothing to post for an adjustment without cost impact: no amount is made up.
        $impact = LedgerAmount::of($event->totalCostImpact, 2);
        $cost = Decimal::abs($impact);
        if (! Decimal::isPositive($cost)) {
            return;
        }

        $isPositive = ! Decimal::isNegative($impact);

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'INV-ADJ-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_adjustment',
            'source_document_id' => $event->adjustmentId,
            'journal_type' => 'standard',
            'description' => "Inventory Adjustment: {$event->reasonCode}",
            'lines' => [
                [
                    'account_id' => $isPositive ? $invAcc->id : $lossAcc->id,
                    'debit_amount' => $cost,
                    'credit_amount' => '0',
                    'description' => 'Inventory Adjustment Debit',
                ],
                [
                    'account_id' => $isPositive ? $gainAcc->id : $invAcc->id,
                    'debit_amount' => '0',
                    'credit_amount' => $cost,
                    'description' => 'Inventory Adjustment Credit',
                ],
            ],
        ]);

        $this->linkAdjustment($event->adjustmentId, (string) $entry->id);
    }

    /**
     * The adjustment points to its journal entry (the entry points back through its source document).
     */
    private function linkAdjustment(string $adjustmentId, string $journalEntryId): void
    {
        InventoryAdjustment::withoutGlobalScopes()
            ->whereKey($adjustmentId)
            ->whereNull('journal_entry_id')
            ->update(['journal_entry_id' => $journalEntryId, 'gl_posted_at' => now()]);
    }

    public function handleWriteOff(InventoryWriteOffEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $scrapAcc = $this->resolveAccount($event->tenantId, '6500', 'Inventory Scrap & Loss Expense', 'expense', 'debit');
        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'INV-SCRAP-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_write_off',
            'source_document_id' => $event->writeOffId,
            'journal_type' => 'standard',
            'description' => "Inventory Write-Off: {$event->reason}",
            'lines' => [
                [
                    'account_id' => $scrapAcc->id,
                    'debit_amount' => LedgerAmount::of($event->totalWriteOffAmount),
                    'credit_amount' => '0',
                    'description' => "Loss on inventory scrap: {$event->reason}",
                ],
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => '0',
                    'credit_amount' => LedgerAmount::of($event->totalWriteOffAmount),
                    'description' => 'Inventory Asset De-recognition',
                ],
            ],
        ]);
    }

    public function handleRevaluation(InventoryRevaluationEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $reserveAcc = $this->resolveAccount($event->tenantId, '3100', 'Inventory Revaluation Reserve', 'equity', 'credit');

        $diff = LedgerAmount::of($event->deltaAmount);
        $absDiff = Decimal::abs($diff);

        if (! Decimal::isPositive($absDiff)) {
            return;
        }

        $isGain = Decimal::isPositive($diff);

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'INV-REVAL-'.strtoupper(Str::random(6)),
            'source_document_type' => 'inventory_revaluation',
            'source_document_id' => $event->revaluationId,
            'journal_type' => 'standard',
            'description' => 'Inventory Revaluation Run',
            'lines' => [
                [
                    'account_id' => $isGain ? $invAcc->id : $reserveAcc->id,
                    'debit_amount' => $absDiff,
                    'credit_amount' => '0',
                    'description' => $isGain ? 'Inventory Asset Appreciation' : 'Revaluation Deficit',
                ],
                [
                    'account_id' => $isGain ? $reserveAcc->id : $invAcc->id,
                    'debit_amount' => '0',
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
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }
}
