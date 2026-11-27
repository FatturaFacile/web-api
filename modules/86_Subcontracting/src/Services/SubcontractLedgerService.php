<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;
use Modules\Subcontracting\Models\SubcontractMaterialLedgerEntry;
use Modules\Subcontracting\Support\StatutoryDeadline;

/**
 * Writes the immutable statutory register of the goods held by third parties. The running balance is the quantity of the
 * component still at the partner after the movement; the one-year return deadline starts with the dispatch (or customer receipt)
 * DDT and is carried by every later line of the same order and product.
 */
final class SubcontractLedgerService
{
    /**
     * @param  array{dispatched?: string, consumed?: string, scrap?: string, returned_unused?: string}  $quantities
     * @param  array{id?: string|null, number?: string|null, date?: string|null, reason?: string|null}  $ddt
     */
    public function post(
        SubcontractingOrder $order,
        SubcontractingOrderComponent $component,
        LedgerMovementType $type,
        array $quantities,
        array $ddt = [],
        ?string $entryDate = null,
        ?string $notes = null,
    ): SubcontractMaterialLedgerEntry {
        return $this->postFor($order, $component->component_product_id, $component->heldQuantity(), $component->lot_id, $type, $quantities, $ddt, $entryDate, $notes);
    }

    /**
     * Posts a line for any product of the order (a component, or the finished goods of an active job) with the balance still held
     * after the movement.
     *
     * @param  array{dispatched?: string, consumed?: string, scrap?: string, returned_unused?: string}  $quantities
     * @param  array{id?: string|null, number?: string|null, date?: string|null, reason?: string|null}  $ddt
     */
    public function postFor(
        SubcontractingOrder $order,
        string $productId,
        string $remaining,
        ?string $lotId,
        LedgerMovementType $type,
        array $quantities,
        array $ddt = [],
        ?string $entryDate = null,
        ?string $notes = null,
    ): SubcontractMaterialLedgerEntry {
        $date = CarbonImmutable::parse($entryDate ?? now()->toDateString())->startOfDay();
        $ddtDate = isset($ddt['date']) ? CarbonImmutable::parse($ddt['date'])->startOfDay() : null;
        $deadline = $type->startsStatutoryClock()
            ? StatutoryDeadline::for($ddtDate ?? $date)
            : $this->carriedDeadline($order, $productId);

        $movedQuantity = Money::qty(Money::add(Money::add($quantities['dispatched'] ?? '0', $quantities['consumed'] ?? '0'), Money::add($quantities['scrap'] ?? '0', $quantities['returned_unused'] ?? '0')));

        return SubcontractMaterialLedgerEntry::create([
            'tenant_id' => $order->tenant_id,
            'direction' => $order->direction->value,
            'subcontracting_order_id' => $order->id,
            'partner_id' => $order->partner_id,
            'virtual_warehouse_id' => $order->subcontractor_warehouse_id,
            'component_product_id' => $productId,
            'lot_id' => $lotId,
            'movement_type' => $type->value,
            'entry_date' => $date->toDateString(),
            'dispatched_qty' => Money::qty($quantities['dispatched'] ?? '0'),
            'consumed_in_returned_goods_qty' => Money::qty($quantities['consumed'] ?? '0'),
            'scrap_reported_by_subcontractor_qty' => Money::qty($quantities['scrap'] ?? '0'),
            'returned_unused_qty' => Money::qty($quantities['returned_unused'] ?? '0'),
            'remaining_at_subcontractor_qty' => Money::qty($remaining),
            'ddt_document_id' => $ddt['id'] ?? null,
            'ddt_number' => $ddt['number'] ?? null,
            'ddt_date' => $ddtDate?->toDateString(),
            'transport_reason' => $ddt['reason'] ?? null,
            'statutory_return_deadline' => $deadline?->toDateString(),
            'customs_declaration_id' => $order->customs_declaration_id,
            'valuation_amount' => $this->valuation($order, $productId, $movedQuantity),
            'notes' => $notes,
        ]);
    }

    /**
     * Customer-owned goods are never an asset of ours: always 0.00. Our own goods at a vendor are valued at the product cost.
     */
    private function valuation(SubcontractingOrder $order, string $productId, string $quantity): string
    {
        if ($order->direction->isCustomerOwned()) {
            return '0.00';
        }

        $cost = (string) (Product::query()->whereKey($productId)->value('cost_price') ?? '0');

        return Money::round(Money::mul($quantity, $cost), 2);
    }

    private function carriedDeadline(SubcontractingOrder $order, string $productId): ?CarbonImmutable
    {
        $value = SubcontractMaterialLedgerEntry::query()
            ->where('subcontracting_order_id', $order->id)
            ->where('component_product_id', $productId)
            ->whereIn('movement_type', [LedgerMovementType::OutboundDispatch->value, LedgerMovementType::CustomerMaterialReceipt->value])
            ->min('statutory_return_deadline');

        return $value !== null ? CarbonImmutable::parse((string) $value) : null;
    }
}
