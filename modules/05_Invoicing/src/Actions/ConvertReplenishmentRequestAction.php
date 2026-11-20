<?php

declare(strict_types=1);

namespace Modules\Invoicing\Actions;

use App\Models\Product;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\ReplenishmentRequest;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Services\ReplenishmentTriggerService;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseRequisitionDTO;
use Throwable;

/**
 * Turns an open replenishment request of a sales order shortage into the real document: a draft purchase requisition
 * (Procurement) for purchased items, a planned production order (Manufacturing) for made ones. One document per request:
 * the request is locked, converted once (`status` converted, the document in `target_type` / `target_id`) and a request
 * already converted is returned untouched. A request that cannot be converted yet (no released BOM, no requesting user)
 * stays open for the planners.
 */
final readonly class ConvertReplenishmentRequestAction
{
    public function __construct(
        private ProcurementServiceInterface $procurement,
        private ProductionPlanningServiceInterface $production,
    ) {}

    /**
     * @return Model|null the document of the request, null when it stays open
     */
    public function execute(string $requestId, string $tenantId): ?Model
    {
        return DB::transaction(function () use ($requestId, $tenantId): ?Model {
            $request = ReplenishmentRequest::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($requestId);

            if ($request === null || $request->status !== 'open' || $request->target_id !== null) {
                return null;
            }

            try {
                // A savepoint: a refused conversion must not poison the transaction of the order that raised the shortage.
                $document = DB::transaction(fn (): ?Model => $request->request_type === ReplenishmentTriggerService::ProductionOrder
                    ? $this->makeProductionOrder($request)
                    : $this->makeRequisition($request));
            } catch (Throwable $exception) {
                Log::warning('Replenishment request left open: '.$exception->getMessage(), ['replenishment_request_id' => $requestId]);

                return null;
            }

            if ($document === null) {
                return null;
            }

            $request->forceFill([
                'status' => 'converted',
                'target_type' => $request->request_type,
                'target_id' => (string) $document->getKey(),
            ])->save();

            return $document;
        });
    }

    private function makeRequisition(ReplenishmentRequest $request): ?Model
    {
        $order = SalesOrder::query()->find($request->sales_order_id);
        $requestor = $this->requestor($request, $order);

        if ($requestor === null) {
            Log::warning('Replenishment request has no requesting user for a requisition.', ['replenishment_request_id' => (string) $request->id]);

            return null;
        }

        $product = Product::query()->findOrFail($request->product_id);

        return $this->procurement->createRequisition(new CreatePurchaseRequisitionDTO(
            title: 'Shortage '.($order->order_number ?? $request->sales_order_id),
            requestorUserId: $requestor,
            requiredByDate: ($request->needed_by ?? CarbonImmutable::now())->toDateString(),
            lines: [[
                'description' => trim($product->sku.' '.$product->name),
                'quantity_requested' => (float) $request->quantity,
                'uom' => $product->unit_measure ?? 'PCE',
                'estimated_unit_price' => (float) ($product->cost_price ?? 0),
            ]],
            currency: strtoupper((string) (Tenant::query()->whereKey($request->tenant_id)->value('default_currency') ?? 'EUR')),
            notes: 'Created from replenishment request '.$request->id,
            tenantId: (string) $request->tenant_id,
            userId: $requestor,
        ));
    }

    private function makeProductionOrder(ReplenishmentRequest $request): ?Model
    {
        $item = MfgItem::query()->where('tenant_id', $request->tenant_id)->where('product_id', $request->product_id)->first();

        if ($item === null) {
            return null;
        }

        $start = CarbonImmutable::now();
        $end = $request->needed_by !== null && $request->needed_by->greaterThan($start) ? CarbonImmutable::instance($request->needed_by) : $start;

        try {
            return $this->production->planOrder(new CreateProductionOrderDTO(
                tenantId: (string) $request->tenant_id,
                mfgItemId: (string) $item->id,
                targetQuantity: (string) $request->quantity,
                plannedStartDate: $start->toDateString(),
                plannedEndDate: $end->toDateString(),
                notes: 'Created from replenishment request '.$request->id,
                salesOrderId: (string) $request->sales_order_id,
                warehouseId: $request->warehouse_id,
                createdBy: $request->created_by,
            ));
        } catch (ManufacturingOperationException $exception) {
            Log::warning('Replenishment request left open: '.$exception->getMessage(), ['replenishment_request_id' => (string) $request->id]);

            return null;
        }
    }

    private function requestor(ReplenishmentRequest $request, ?SalesOrder $order): ?string
    {
        foreach ([$request->created_by, $order?->created_by] as $candidate) {
            if (is_string($candidate) && Str::isUuid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
