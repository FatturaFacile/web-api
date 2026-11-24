<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\DTOs\LandedCostAllocationLine;
use Modules\Inventory\Models\InventoryRevaluation;
use Modules\Inventory\Models\LandedCostAllocation;
use Modules\Inventory\Models\LandedCostDocument;
use Modules\Inventory\Support\Decimal;

/**
 * @mixin LandedCostDocument
 */
final class LandedCostDocumentResource extends JsonResource
{
    /** @var list<LandedCostAllocationLine>|null */
    private ?array $previewAllocations = null;

    /**
     * Draft documents expose a live allocation preview (nothing persisted).
     *
     * @param  list<LandedCostAllocationLine>  $lines
     */
    public function withPreview(array $lines): self
    {
        $this->previewAllocations = $lines;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LandedCostDocument $document */
        $document = $this->resource;
        $receiptNumbers = $document->receiptNumbers();

        $allocations = $this->previewAllocations !== null
            ? array_map(static fn (LandedCostAllocationLine $line): array => self::allocationFromLine($line), $this->previewAllocations)
            : $document->allocations()->get()->map(static fn (LandedCostAllocation $allocation): array => self::allocationFromModel($allocation))->values()->all();

        /** @var InventoryRevaluation|null $revaluation */
        $revaluation = $document->revaluation_id !== null ? $document->revaluation()->first() : null;

        return [
            'id' => $document->id,
            'number' => $document->number,
            'status' => $document->status,
            'cost_type' => $document->cost_type,
            'allocation_key' => $document->allocation_key,
            'currency' => $document->currency,
            'exchange_rate' => Decimal::round((string) $document->exchange_rate, 8),
            'total_amount' => Decimal::money((string) $document->total_amount),
            'document_date' => $document->document_date->toDateString(),
            'supplier_invoice_id' => $document->supplier_invoice_id,
            'notes' => $document->notes,
            'receipts' => array_map(
                static fn (string $receiptId): array => [
                    'receipt_id' => $receiptId,
                    'receipt_number' => $receiptNumbers[$receiptId] ?? null,
                ],
                $document->receiptIds()
            ),
            'allocations' => $allocations,
            'journal_entry_id' => $document->journal_entry_id,
            'applied_at' => $document->applied_at?->toIso8601String(),
            'reversal_of_id' => $document->reversal_of_id,
            'reversed_at' => $document->reversed_at?->toIso8601String(),
            'reversal_reason' => $document->reversal_reason,
            'revaluation' => $revaluation !== null ? [
                'id' => $revaluation->id,
                'total_inventory_delta' => Decimal::money((string) $revaluation->total_inventory_delta),
                'total_cogs_delta' => Decimal::money((string) $revaluation->total_cogs_delta),
                'total_variance_delta' => Decimal::money((string) $revaluation->total_variance_delta),
                'journal_entry_id' => $revaluation->journal_entry_id,
            ] : null,
            'created_by' => $document->created_by,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function allocationFromLine(LandedCostAllocationLine $line): array
    {
        return [
            'receipt_id' => $line->receiptId,
            'item_id' => $line->itemId,
            'lot_id' => $line->lotId,
            'cost_layer_id' => $line->costLayerId,
            'valuation_method' => $line->method->value,
            'basis_value' => Decimal::round($line->basisValue, 6),
            'allocated_amount' => Decimal::money($line->allocatedAmount),
            'to_inventory_amount' => Decimal::money($line->toInventoryAmount),
            'to_cogs_amount' => Decimal::money($line->toCogsAmount),
            'to_variance_amount' => Decimal::money($line->toVarianceAmount),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function allocationFromModel(LandedCostAllocation $allocation): array
    {
        return [
            'receipt_id' => (string) $allocation->purchase_receipt_id,
            'item_id' => (string) $allocation->stock_item_id,
            'lot_id' => $allocation->lot_id !== null ? (string) $allocation->lot_id : null,
            'cost_layer_id' => $allocation->cost_layer_id !== null ? (string) $allocation->cost_layer_id : null,
            'valuation_method' => (string) $allocation->valuation_method,
            'basis_value' => Decimal::round((string) $allocation->basis_value, 6),
            'allocated_amount' => Decimal::money((string) $allocation->allocated_amount),
            'to_inventory_amount' => Decimal::money((string) $allocation->to_inventory_amount),
            'to_cogs_amount' => Decimal::money((string) $allocation->to_cogs_amount),
            'to_variance_amount' => Decimal::money((string) $allocation->to_variance_amount),
        ];
    }
}
