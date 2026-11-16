<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Contracts\InventoryLedgerGatewayInterface;
use Modules\Inventory\DTOs\LandedCostAllocationLine;
use Modules\Inventory\Enums\LandedCostStatus;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Events\InventoryRevaluedEvent;
use Modules\Inventory\Events\LandedCostAppliedEvent;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\LandedCostAllocation;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Support\Decimal;
use Modules\Inventory\Support\JournalLegs;

/**
 * Applies landed costs and other revaluations to cost layers / weighted average cost and raises
 * the events the General Ledger turns into adjustment journals. Historical stock movements are
 * never modified. Callers must run inside a DB transaction.
 */
final class InventoryRevaluationService
{
    public function __construct(
        private readonly InventoryCostingServiceInterface $costing,
        private readonly InventoryLedgerGatewayInterface $ledger
    ) {}

    /**
     * Resolves the posting date for a valuation journal dated $date (the date itself, or the first
     * open period when no period covers it). Throws 422 when the period is closed/locked.
     */
    public function resolvePostingDate(string $tenantId, ?string $companyId, string $date, string $field = 'document_date'): string
    {
        $period = $this->ledger->resolvePostingPeriod($tenantId, $companyId, $date);
        if ($period === null) {
            throw ValidationException::withMessages([
                $field => ["The fiscal period for {$date} is closed or locked and no later open period is available."],
            ]);
        }

        return $period['posting_date'];
    }

    /**
     * @param  list<LandedCostAllocationLine>  $lines
     */
    public function applyLandedCost(LandedCostDocument $document, array $lines, string $postingDate, ?string $actorId): InventoryRevaluation
    {
        $revaluation = new InventoryRevaluation([
            'tenant_id' => $document->tenant_id,
            'company_id' => $document->company_id,
            'source_type' => 'landed_cost',
            'source_id' => $document->id,
            'status' => 'posted',
            'posting_date' => $postingDate,
            'created_by' => $actorId,
        ]);

        $legs = new JournalLegs;
        $totals = ['inventory' => '0', 'cogs' => '0', 'variance' => '0'];
        $revaluationLines = [];
        $clearingAccount = null;

        foreach ($lines as $line) {
            /** @var InventoryCostLayer $layer */
            $layer = InventoryCostLayer::query()->whereKey($line->costLayerId)->lockForUpdate()->firstOrFail();
            /** @var StockItem $item */
            $item = StockItem::query()->whereKey($line->itemId)->lockForUpdate()->firstOrFail();

            [$layerUplift, $averageUplift] = $this->upliftCosts($line, $layer, $item);

            LandedCostAllocation::create([
                'tenant_id' => $document->tenant_id,
                'landed_cost_document_id' => $document->id,
                'purchase_receipt_id' => $line->receiptId,
                'stock_item_id' => $line->itemId,
                'lot_id' => $line->lotId,
                'cost_layer_id' => $line->costLayerId,
                'valuation_method' => $line->method->value,
                'basis_value' => $line->basisValue,
                'allocated_amount' => $line->allocatedAmount,
                'to_inventory_amount' => $line->toInventoryAmount,
                'to_cogs_amount' => $line->toCogsAmount,
                'to_variance_amount' => $line->toVarianceAmount,
                'layer_unit_uplift' => $layerUplift,
                'average_cost_uplift' => $averageUplift,
            ]);

            $accounts = $this->costing->accountOverrides($item);
            $clearingAccount ??= $accounts[JournalLegs::ROLE_LANDED_COST_CLEARING];
            $legs->debit(JournalLegs::ROLE_INVENTORY, $accounts[JournalLegs::ROLE_INVENTORY], $line->toInventoryAmount, "Landed cost {$document->number}: inventory");
            $legs->debit(JournalLegs::ROLE_COGS, $accounts[JournalLegs::ROLE_COGS], $line->toCogsAmount, "Landed cost {$document->number}: goods already sold");
            $legs->debit(JournalLegs::ROLE_PPV, $accounts[JournalLegs::ROLE_PPV], $line->toVarianceAmount, "Landed cost {$document->number}: standard cost variance");

            $totals['inventory'] = Decimal::add($totals['inventory'], $line->toInventoryAmount);
            $totals['cogs'] = Decimal::add($totals['cogs'], $line->toCogsAmount);
            $totals['variance'] = Decimal::add($totals['variance'], $line->toVarianceAmount);
            $revaluationLines[] = $line->toArray() + ['layer_unit_uplift' => $layerUplift, 'average_cost_uplift' => $averageUplift];
        }

        $total = Decimal::add(Decimal::add($totals['inventory'], $totals['cogs']), $totals['variance']);
        $legs->credit(JournalLegs::ROLE_LANDED_COST_CLEARING, $clearingAccount, $total, "Landed cost {$document->number}: clearing");

        $revaluation->fill([
            'total_inventory_delta' => Decimal::money($totals['inventory']),
            'total_cogs_delta' => Decimal::money($totals['cogs']),
            'total_variance_delta' => Decimal::money($totals['variance']),
            'lines' => $revaluationLines,
        ]);
        $revaluation->save();

        $journalEntryId = $this->postLandedCostJournal($document, $revaluation, $legs->toArray(), $total, $postingDate, false);
        $revaluation->forceFill(['journal_entry_id' => $journalEntryId])->save();

        return $revaluation;
    }

    /**
     * Reverses an applied landed cost document: creates the reversal document (negative amounts),
     * reverts the layer / average cost uplift on remaining stock and posts the mirror journal.
     */
    public function reverseLandedCost(LandedCostDocument $original, string $reason, string $postingDate, ?string $actorId): LandedCostDocument
    {
        $reversal = LandedCostDocument::create([
            'tenant_id' => $original->tenant_id,
            'company_id' => $original->company_id,
            'number' => $original->number.'-R',
            'status' => LandedCostStatus::Applied->value,
            'cost_type' => $original->cost_type,
            'allocation_key' => $original->allocation_key,
            'currency' => $original->currency,
            'exchange_rate' => (string) $original->exchange_rate,
            'total_amount' => Decimal::money(Decimal::neg((string) $original->total_amount)),
            'supplier_invoice_id' => $original->supplier_invoice_id,
            'document_date' => $postingDate,
            'notes' => $reason,
            'created_by' => $actorId,
            'applied_at' => Carbon::now(),
            'applied_by' => $actorId,
            'reversal_of_id' => $original->id,
        ]);

        foreach ($original->receipts()->get() as $receipt) {
            $reversal->receipts()->create([
                'tenant_id' => $original->tenant_id,
                'purchase_receipt_id' => $receipt->purchase_receipt_id,
            ]);
        }

        $legs = new JournalLegs;
        $totals = ['inventory' => '0', 'cogs' => '0', 'variance' => '0'];
        $revaluationLines = [];
        $clearingAccount = null;

        foreach ($original->allocations()->get() as $allocation) {
            $layer = $allocation->cost_layer_id !== null
                ? InventoryCostLayer::query()->whereKey($allocation->cost_layer_id)->lockForUpdate()->first()
                : null;
            /** @var StockItem $item */
            $item = StockItem::query()->whereKey($allocation->stock_item_id)->lockForUpdate()->firstOrFail();

            if ($layer !== null && Decimal::isPositive((string) $allocation->layer_unit_uplift)) {
                $layer->forceFill([
                    'landed_cost_unit' => Decimal::unitCost(Decimal::max('0', Decimal::sub((string) $layer->landed_cost_unit, (string) $allocation->layer_unit_uplift))),
                ])->save();
            }

            if (Decimal::isPositive((string) $allocation->average_cost_uplift)) {
                $item->forceFill([
                    'current_moving_average_cost' => Decimal::unitCost(Decimal::max('0', Decimal::sub((string) $item->current_moving_average_cost, (string) $allocation->average_cost_uplift))),
                ])->save();
            }

            $negated = [
                'allocated_amount' => Decimal::money(Decimal::neg((string) $allocation->allocated_amount)),
                'to_inventory_amount' => Decimal::money(Decimal::neg((string) $allocation->to_inventory_amount)),
                'to_cogs_amount' => Decimal::money(Decimal::neg((string) $allocation->to_cogs_amount)),
                'to_variance_amount' => Decimal::money(Decimal::neg((string) $allocation->to_variance_amount)),
            ];

            LandedCostAllocation::create([
                'tenant_id' => $original->tenant_id,
                'landed_cost_document_id' => $reversal->id,
                'purchase_receipt_id' => $allocation->purchase_receipt_id,
                'stock_item_id' => $allocation->stock_item_id,
                'lot_id' => $allocation->lot_id,
                'cost_layer_id' => $allocation->cost_layer_id,
                'valuation_method' => $allocation->valuation_method,
                'basis_value' => (string) $allocation->basis_value,
                'layer_unit_uplift' => Decimal::unitCost(Decimal::neg((string) $allocation->layer_unit_uplift)),
                'average_cost_uplift' => Decimal::unitCost(Decimal::neg((string) $allocation->average_cost_uplift)),
            ] + $negated);

            $accounts = $this->costing->accountOverrides($item);
            $clearingAccount ??= $accounts[JournalLegs::ROLE_LANDED_COST_CLEARING];
            $legs->credit(JournalLegs::ROLE_INVENTORY, $accounts[JournalLegs::ROLE_INVENTORY], (string) $allocation->to_inventory_amount, "Reversal of landed cost {$original->number}: inventory");
            $legs->credit(JournalLegs::ROLE_COGS, $accounts[JournalLegs::ROLE_COGS], (string) $allocation->to_cogs_amount, "Reversal of landed cost {$original->number}: goods already sold");
            $legs->credit(JournalLegs::ROLE_PPV, $accounts[JournalLegs::ROLE_PPV], (string) $allocation->to_variance_amount, "Reversal of landed cost {$original->number}: standard cost variance");

            $totals['inventory'] = Decimal::add($totals['inventory'], $negated['to_inventory_amount']);
            $totals['cogs'] = Decimal::add($totals['cogs'], $negated['to_cogs_amount']);
            $totals['variance'] = Decimal::add($totals['variance'], $negated['to_variance_amount']);
            $revaluationLines[] = ['cost_layer_id' => $allocation->cost_layer_id, 'item_id' => $allocation->stock_item_id] + $negated;
        }

        $total = Decimal::add(Decimal::add($totals['inventory'], $totals['cogs']), $totals['variance']);
        $legs->debit(JournalLegs::ROLE_LANDED_COST_CLEARING, $clearingAccount, Decimal::abs($total), "Reversal of landed cost {$original->number}: clearing");

        $originalRevaluation = $original->revaluation_id !== null ? InventoryRevaluation::find($original->revaluation_id) : null;

        $revaluation = InventoryRevaluation::create([
            'tenant_id' => $original->tenant_id,
            'company_id' => $original->company_id,
            'source_type' => 'landed_cost',
            'source_id' => $reversal->id,
            'status' => 'posted',
            'posting_date' => $postingDate,
            'total_inventory_delta' => Decimal::money($totals['inventory']),
            'total_cogs_delta' => Decimal::money($totals['cogs']),
            'total_variance_delta' => Decimal::money($totals['variance']),
            'reversal_of_id' => $originalRevaluation?->id,
            'lines' => $revaluationLines,
            'created_by' => $actorId,
        ]);

        $journalEntryId = $this->postLandedCostJournal($reversal, $revaluation, $legs->toArray(), Decimal::abs($total), $postingDate, true);
        $revaluation->forceFill(['journal_entry_id' => $journalEntryId])->save();
        $originalRevaluation?->forceFill(['status' => 'reversed'])->save();

        $reversal->forceFill([
            'journal_entry_id' => $journalEntryId,
            'revaluation_id' => $revaluation->id,
        ])->save();

        $original->forceFill([
            'status' => LandedCostStatus::Reversed->value,
            'reversed_at' => Carbon::now(),
            'reversed_by' => $actorId,
            'reversal_reason' => $reason,
        ])->save();

        return $reversal;
    }

    /**
     * Revalues on-hand stock after a valuation method / standard cost change: delta = new value - old value,
     * booked against COGS. Returns null when there is no on-hand stock.
     */
    public function revalueMethodChange(
        StockItem $item,
        ValuationMethod $oldMethod,
        string $oldValue,
        ValuationMethod $newMethod,
        string $newValue,
        ?string $actorId
    ): ?InventoryRevaluation {
        $onHand = Decimal::quantity((string) StockQuant::query()->where('stock_item_id', $item->id)->sum('quantity_on_hand'));
        if (! Decimal::isPositive($onHand)) {
            return null;
        }

        $delta = Decimal::money(Decimal::sub($newValue, $oldValue));
        $today = Carbon::now()->toDateString();
        $postingDate = Decimal::isZero($delta)
            ? $today
            : $this->resolvePostingDate((string) $item->tenant_id, $item->company_id !== null ? (string) $item->company_id : null, $today, 'valuation_method');

        $revaluation = InventoryRevaluation::create([
            'tenant_id' => $item->tenant_id,
            'company_id' => $item->company_id,
            'source_type' => 'method_change',
            'source_id' => $item->id,
            'status' => 'posted',
            'posting_date' => $postingDate,
            'total_inventory_delta' => $delta,
            'total_cogs_delta' => Decimal::money(Decimal::neg($delta)),
            'total_variance_delta' => '0.00',
            'lines' => [[
                'item_id' => (string) $item->id,
                'sku' => (string) $item->sku,
                'quantity' => $onHand,
                'old_method' => $oldMethod->value,
                'new_method' => $newMethod->value,
                'old_value' => Decimal::money($oldValue),
                'new_value' => Decimal::money($newValue),
            ]],
            'created_by' => $actorId,
        ]);

        if (Decimal::isZero($delta)) {
            return $revaluation;
        }

        $accounts = $this->costing->accountOverrides($item);
        $legs = (new JournalLegs)
            ->debit(JournalLegs::ROLE_INVENTORY, $accounts[JournalLegs::ROLE_INVENTORY], $delta, "Revaluation {$item->sku}: {$oldMethod->value} -> {$newMethod->value}")
            ->credit(JournalLegs::ROLE_COGS, $accounts[JournalLegs::ROLE_COGS], $delta, "Revaluation {$item->sku}: offset")
            ->toArray();

        event(new InventoryRevaluedEvent(
            revaluationId: (string) $revaluation->id,
            sourceType: 'method_change',
            postingDate: $postingDate,
            totalInventoryDelta: $delta,
            totalCogsDelta: Decimal::money(Decimal::neg($delta)),
            legs: $legs,
            tenantId: (string) $item->tenant_id,
            companyId: (string) ($item->company_id ?? $item->tenant_id),
            description: "Inventory revaluation {$item->sku} ({$oldMethod->value} -> {$newMethod->value})",
            eventId: (string) Str::uuid7(),
        ));

        $revaluation->forceFill([
            'journal_entry_id' => $this->ledger->findJournalEntryId((string) $item->tenant_id, 'inventory_revaluation', (string) $revaluation->id),
        ])->save();

        return $revaluation;
    }

    /**
     * Uplifts the layer (FIFO / traceability) and the weighted average cost (WAC) with the inventory share.
     *
     * @return array{0: string, 1: string} [layer unit uplift, average cost uplift]
     */
    private function upliftCosts(LandedCostAllocationLine $line, InventoryCostLayer $layer, StockItem $item): array
    {
        $toInventory = $line->toInventoryAmount;
        if (! Decimal::isPositive($toInventory)) {
            return ['0.0000', '0.0000'];
        }

        $layerUplift = '0.0000';
        $remaining = (string) $layer->remaining_quantity;
        if (Decimal::isPositive($remaining)) {
            $layerUplift = Decimal::unitCost(Decimal::div($toInventory, $remaining));
            $layer->forceFill([
                'landed_cost_unit' => Decimal::unitCost(Decimal::add((string) $layer->landed_cost_unit, $layerUplift)),
            ])->save();
        }

        $averageUplift = '0.0000';
        if ($line->method === ValuationMethod::WeightedAverage) {
            $onHand = Decimal::quantity((string) StockQuant::query()->where('stock_item_id', $item->id)->sum('quantity_on_hand'));
            if (Decimal::isPositive($onHand)) {
                $current = Decimal::unitCost((string) $item->current_moving_average_cost);
                $newAverage = Decimal::unitCost(Decimal::div(Decimal::add(Decimal::mul($onHand, $current), $toInventory), $onHand));
                $averageUplift = Decimal::unitCost(Decimal::sub($newAverage, $current));
                $item->forceFill(['current_moving_average_cost' => $newAverage])->save();
            }
        }

        return [$layerUplift, $averageUplift];
    }

    /**
     * @param  list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>  $legs
     */
    private function postLandedCostJournal(
        LandedCostDocument $document,
        InventoryRevaluation $revaluation,
        array $legs,
        string $total,
        string $postingDate,
        bool $isReversal
    ): ?string {
        if (! JournalLegs::isBalanced($legs)) {
            return null;
        }

        event(new LandedCostAppliedEvent(
            landedCostDocumentId: (string) $document->id,
            documentNumber: (string) $document->number,
            revaluationId: (string) $revaluation->id,
            postingDate: $postingDate,
            totalAmount: Decimal::money($total),
            legs: $legs,
            isReversal: $isReversal,
            tenantId: (string) $document->tenant_id,
            companyId: (string) ($document->company_id ?? $document->tenant_id),
            eventId: (string) Str::uuid7(),
        ));

        return $this->ledger->findJournalEntryId((string) $document->tenant_id, 'landed_cost', (string) $document->id);
    }
}
