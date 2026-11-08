<?php

declare(strict_types=1);

namespace App\Actions\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Procurement\Models\PurchaseOrder as ProcurementOrder;
use Modules\Procurement\Services\ProcurementApprovalService;

/**
 * Purchase orders of the purchasing API of the app: numbered from the shared gapless series and moved through the shared
 * status vocabulary (App\Enums\PurchaseOrderStatus) with the procurement module.
 */
class SavePurchaseOrder
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly DocumentEventRecorder $events,
        private readonly ProcurementApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $items = $data['items'];
            unset($data['items']);

            $order = new PurchaseOrder;
            $order->fill($data);
            $order->order_number = $this->numbering->next('purchase_order', (int) date('Y', strtotime((string) $data['date'])))['number'];
            $order->status = PurchaseOrderStatus::Draft->value;
            $this->fillTotals($order, $items);
            $order->save();

            $order->items()->createMany($this->itemRows($items));
            $this->events->record($order, 'created');

            return $order->load(['items', 'supplier']);
        });
    }

    /**
     * Only draft or sent orders can be edited; received quantities are never reset.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        if (! in_array($order->status, [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value], true)) {
            throw ValidationException::withMessages(['order' => __('purchases.order_locked')]);
        }

        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $items = $data['items'] ?? null;
            unset($data['items']);

            $order->fill($data);

            if ($items !== null) {
                $this->fillTotals($order, $items);
                $order->items()->delete();
                $order->items()->createMany($this->itemRows($items));
            }

            $order->save();
            $this->events->record($order, 'updated');

            return $order->load(['items', 'supplier']);
        });
    }

    /**
     * Sends a draft order to the supplier, or an order approved by the procurement workflow. A draft that an approval
     * matrix or workflow of the company would have to approve must be submitted for approval first.
     */
    public function confirm(PurchaseOrder $order): PurchaseOrder
    {
        $status = (string) $order->status;
        $approvable = $status === PurchaseOrderStatus::Draft->value ? ProcurementOrder::query()->find($order->id) : null;

        if (! in_array($status, [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Approved->value], true)
            || ($approvable !== null && $this->approvals->requiresApproval($approvable))) {
            throw ValidationException::withMessages(['order' => __('purchases.order_not_confirmable')]);
        }

        PurchaseOrderStatus::assertTransition($status, PurchaseOrderStatus::Sent);
        $order->update(['status' => PurchaseOrderStatus::Sent->value]);
        $this->events->record($order, 'confirmed');

        return $order;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function fillTotals(PurchaseOrder $order, array $items): void
    {
        $computed = $this->totals->calculate($items);
        $order->total_net = $computed['total_net'];
        $order->total_tax = $computed['total_tax'];
        $order->total_gross = $computed['total_gross'];
        // The procurement module reads the same order through these columns (approval matrices, analytics).
        $order->forceFill(['net_amount' => $computed['total_net'], 'tax_amount' => $computed['total_tax'], 'total_amount' => $computed['total_gross']]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function itemRows(array $items): array
    {
        return array_map(
            fn (array $row): array => collect($row)->only(['product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'line_total'])->all(),
            $this->totals->calculate($items)['items']
        );
    }
}
