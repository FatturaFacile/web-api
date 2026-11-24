<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\PurchaseOrderLineStockItemResolver;
use Modules\Inventory\Services\SupplierReceiptStockService;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Models\LandedCostAllocation;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Support\Decimal;

/**
 * Landed costs: freight, duty, insurance and other costs of purchased goods, allocated to the lines of one or more
 * goods receipts by value (received value at cost), weight (unit weight of the order line) or quantity. Posting adds
 * each share to the moving average cost of the stock item through the inventory module and books inventory against
 * the landed cost clearing account through the general ledger. The amount is in the accounting currency.
 */
final readonly class LandedCostService
{
    public const array Methods = ['value', 'weight', 'quantity'];

    public const array CostTypes = ['freight', 'duty', 'insurance', 'handling', 'other'];

    public function __construct(
        private ProcurementNumberingService $numbering,
        private ProcurementSettingsService $settings,
        private SupplierReceiptStockService $stock,
        private PurchaseOrderLineStockItemResolver $stockItems,
        private ProcurementLedgerService $ledger,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    /**
     * Creates a draft landed cost and allocates it to the lines of the receipts.
     *
     * @param  array{cost_type: string, allocation_method: string, amount: numeric-string|float|int, currency?: ?string, cost_date: string, description?: ?string, passive_invoice_id?: ?string, supplier_id?: ?string, purchase_receipt_ids: list<string>}  $data
     *
     * @throws ProcurementRuleViolation when the basis of the allocation is zero
     */
    public function create(string $tenantId, array $data, ?string $userId): LandedCost
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): LandedCost {
            $landedCost = LandedCost::create([
                'tenant_id' => $tenantId,
                'landed_cost_number' => $this->numbering->next(ProcurementNumberingService::LandedCost, $tenantId, $data['cost_date']),
                'cost_type' => $data['cost_type'],
                'allocation_method' => $data['allocation_method'],
                'status' => 'draft',
                'amount' => Decimal::of($data['amount'], 2),
                'currency' => strtoupper((string) ($data['currency'] ?? 'EUR')),
                'passive_invoice_id' => $data['passive_invoice_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'cost_date' => $data['cost_date'],
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);

            $this->allocate($landedCost, $data['purchase_receipt_ids']);

            return $landedCost->fresh('allocations') ?? $landedCost;
        });
    }

    /**
     * Posts a draft landed cost: stock value and ledger.
     *
     * @throws ProcurementRuleViolation when the landed cost is not a draft
     */
    public function post(LandedCost $landedCost): LandedCost
    {
        return DB::transaction(function () use ($landedCost): LandedCost {
            $locked = LandedCost::query()->whereKey($landedCost->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new ProcurementRuleViolation("A landed cost in status {$locked->status} cannot be posted.", 'status');
            }

            foreach ($locked->allocations()->get() as $allocation) {
                $line = PurchaseReceiptLine::query()->whereKey($allocation->purchase_receipt_line_id)->lockForUpdate()->firstOrFail();
                $line->update(['landed_cost_amount' => Decimal::add((string) $line->landed_cost_amount, (string) $allocation->allocated_amount, 2)]);
                $receipt = PurchaseReceipt::query()->whereKey($allocation->purchase_receipt_id)->lockForUpdate()->firstOrFail();
                $receipt->update(['landed_cost_amount' => Decimal::add((string) $receipt->landed_cost_amount, (string) $allocation->allocated_amount, 2)]);

                $item = $this->stockItems->resolve((string) $line->purchase_order_line_id, (string) $line->tenant_id);

                if ($item !== null) {
                    $this->stock->capitalizeAdditionalCost($item, (string) $allocation->allocated_amount);
                    $allocation->update(['stock_item_id' => $item->id]);
                }
            }

            $tenantId = (string) $locked->tenant_id;
            $entry = $this->ledger->postLandedCost($tenantId, $locked->company_id, (string) $locked->id, (string) $locked->landed_cost_number, $locked->cost_date->toDateString(), (string) $locked->amount, (string) $this->settings->forTenant($tenantId)->landed_cost_clearing_account_code);

            $locked->update(['status' => 'posted', 'posted_at' => now(), 'journal_entry_id' => $entry->id]);
            $this->auditLedger->record(action: 'LANDED_COST_POSTED', model: $locked, payloadBefore: ['status' => 'draft'], payloadAfter: ['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $locked->fresh('allocations') ?? $locked;
        });
    }

    /**
     * Cancels a draft landed cost.
     */
    public function cancel(LandedCost $landedCost): LandedCost
    {
        return DB::transaction(function () use ($landedCost): LandedCost {
            $locked = LandedCost::query()->whereKey($landedCost->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new ProcurementRuleViolation('Only a draft landed cost can be cancelled.', 'status');
            }

            $locked->update(['status' => 'cancelled']);

            return $locked;
        });
    }

    /**
     * Splits the amount on the receipt lines by the method; the rounding remainder goes to the largest share.
     *
     * @param  list<string>  $receiptIds
     *
     * @throws ProcurementRuleViolation when no line has a basis
     */
    private function allocate(LandedCost $landedCost, array $receiptIds): void
    {
        $receipts = PurchaseReceipt::query()->whereIn('id', array_unique($receiptIds))->get();

        if ($receipts->count() !== count(array_unique($receiptIds))) {
            throw new ProcurementRuleViolation('A goods receipt does not exist in the active company.', 'purchase_receipt_ids');
        }

        $lines = PurchaseReceiptLine::query()->with('orderLine')->whereIn('purchase_receipt_id', $receipts->pluck('id'))->get();
        $bases = [];
        $total = '0';

        foreach ($lines as $line) {
            $quantity = (string) $line->quantity_accepted;
            $basis = match ($landedCost->allocation_method) {
                'value' => Decimal::mul($quantity, (string) ($line->unit_cost ?? $line->orderLine->unit_price ?? '0'), 4),
                'weight' => Decimal::mul($quantity, (string) ($line->orderLine->unit_weight ?? '0'), 4),
                default => Decimal::of($quantity, 4),
            };

            if (Decimal::isPositive($basis)) {
                $bases[(string) $line->id] = [$line, $basis];
                $total = Decimal::add($total, $basis, 4);
            }
        }

        if ($bases === []) {
            throw new ProcurementRuleViolation("No receipt line has a {$landedCost->allocation_method} to allocate the cost on.", 'allocation_method');
        }

        $amount = (string) $landedCost->amount;
        $allocated = '0.00';
        $largest = null;
        $rows = [];

        foreach ($bases as $id => [$line, $basis]) {
            $share = Decimal::round(bcdiv(bcmul($amount, $basis, 10), $total, 10), 2);
            $allocated = Decimal::add($allocated, $share, 2);
            $rows[$id] = ['line' => $line, 'basis' => $basis, 'share' => $share];

            if ($largest === null || Decimal::cmp($basis, $rows[$largest]['basis'], 4) > 0) {
                $largest = $id;
            }
        }

        $rows[$largest]['share'] = Decimal::add($rows[$largest]['share'], Decimal::sub($amount, $allocated, 2), 2);

        foreach ($rows as $row) {
            LandedCostAllocation::create([
                'tenant_id' => $landedCost->tenant_id,
                'landed_cost_id' => $landedCost->id,
                'purchase_receipt_id' => $row['line']->purchase_receipt_id,
                'purchase_receipt_line_id' => $row['line']->id,
                'basis_value' => $row['basis'],
                'allocated_amount' => $row['share'],
            ]);
        }
    }
}
