<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Enums\PurchaseOrderDispatchChannel;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\CreateDropshipmentRequest;
use Modules\Procurement\Http\Requests\DispatchPurchaseOrderRequest;
use Modules\Procurement\Http\Requests\IssueDropshipmentDdtRequest;
use Modules\Procurement\Http\Requests\PurchasePricingPreviewRequest;
use Modules\Procurement\Http\Requests\RecordDropshipmentDeliveryRequest;
use Modules\Procurement\Http\Requests\StorePurchaseOrderConfirmationRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderConfirmationResource;
use Modules\Procurement\Http\Resources\PurchaseOrderDispatchResource;
use Modules\Procurement\Http\Resources\PurchaseOrderDropshipResource;
use Modules\Procurement\Http\Resources\PurchaseOrderReminderResource;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderDropship;
use Modules\Procurement\Services\DropshipmentService;
use Modules\Procurement\Services\OverdueOrderReminderService;
use Modules\Procurement\Services\PurchaseOrderDispatchService;
use Modules\Procurement\Services\PurchasePricingService;
use Modules\Procurement\Services\SupplierConfirmationService;
use Modules\Procurement\Support\Decimal;
use OpenApi\Attributes as OA;

/**
 * Mod 046: transmission of purchase orders, supplier confirmations, overdue orders and reminders, the dropshipment
 * (triangulation) flow, and the pricing preview of a line (cascading discounts, dual unit of measure, currency).
 */
#[OA\Tag(name: 'Procurement Fulfilment', description: 'Dispatch, supplier confirmations, overdue reminders and dropshipment')]
final class PurchaseOrderFulfilmentController extends Controller
{
    use ProcurementControllerHelpers;

    #[OA\Post(path: '/api/v1/procurement/purchase-orders/{id}/dispatch', operationId: 'procurementOrdersDispatch', summary: 'Transmit a purchase order', description: 'Sends an approved order to the supplier by PDF, email, EDI or Peppol and logs the dispatch; the first one moves the order to sent.', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 201, description: 'Dispatch logged'), new OA\Response(response: 422, description: 'Order not approved or recipient missing')])]
    public function dispatchOrder(string $id, DispatchPurchaseOrderRequest $request, PurchaseOrderDispatchService $service): JsonResponse
    {
        $order = PurchaseOrder::query()->findOrFail($id);
        $this->authorize('dispatch', $order);
        $validated = $request->validated();

        $dispatch = $service->dispatch($order, PurchaseOrderDispatchChannel::from($validated['channel']), $validated['recipient'] ?? null, $this->actorId($request));

        return response()->json(new PurchaseOrderDispatchResource($dispatch), 201);
    }

    #[OA\Get(path: '/api/v1/procurement/purchase-orders/{id}/dispatches', operationId: 'procurementOrdersDispatches', summary: 'Dispatch log of a purchase order', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Dispatch log')])]
    public function dispatches(string $id): AnonymousResourceCollection
    {
        $order = PurchaseOrder::query()->findOrFail($id);
        $this->authorize('view', $order);

        return PurchaseOrderDispatchResource::collection($order->dispatches()->orderByDesc('dispatched_at')->get());
    }

    #[OA\Post(path: '/api/v1/procurement/purchase-orders/{id}/confirmations', operationId: 'procurementOrdersConfirmationsStore', summary: 'Record the supplier order confirmation', description: 'Stores the confirmation number, the confirmed dates, quantities and prices, computes the variances and moves the promised delivery date to MRP and the linked sales order.', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 201, description: 'Confirmation recorded'), new OA\Response(response: 422, description: 'Order cannot be confirmed')])]
    public function storeConfirmation(string $id, StorePurchaseOrderConfirmationRequest $request, SupplierConfirmationService $service): JsonResponse
    {
        $order = PurchaseOrder::query()->findOrFail($id);
        $this->authorize('confirm', $order);

        return response()->json(new PurchaseOrderConfirmationResource($service->record($order, $request->validated(), $this->actorId($request))), 201);
    }

    #[OA\Get(path: '/api/v1/procurement/purchase-orders/{id}/confirmations', operationId: 'procurementOrdersConfirmationsIndex', summary: 'Supplier confirmations of a purchase order', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Confirmations with line variances')])]
    public function confirmations(string $id): AnonymousResourceCollection
    {
        $order = PurchaseOrder::query()->findOrFail($id);
        $this->authorize('view', $order);

        return PurchaseOrderConfirmationResource::collection($order->confirmations()->with('lines')->orderByDesc('created_at')->get());
    }

    #[OA\Get(path: '/api/v1/procurement/purchase-orders/overdue', operationId: 'procurementOrdersOverdue', summary: 'Overdue purchase orders', description: 'Open orders past their promised (else requested) delivery date, with the days late, the late lines and whether the next reminder is due.', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Overdue orders')])]
    public function overdue(OverdueOrderReminderService $service): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $rows = $service->overdue($this->tenantId())->map(static fn (array $row): array => [
            'order' => (new PurchaseOrderResource($row['order']))->resolve(),
            'days_overdue' => $row['days_overdue'],
            'overdue_line_ids' => $row['overdue_line_ids'],
            'reminder_count' => (int) $row['order']->reminder_count,
            'last_reminder_at' => $row['order']->last_reminder_at?->toIso8601String(),
            'next_reminder_due' => $row['next_reminder_due'],
        ])->all();

        return response()->json(['data' => $rows]);
    }

    #[OA\Post(path: '/api/v1/procurement/purchase-orders/{id}/reminders', operationId: 'procurementOrdersRemindersStore', summary: 'Send a manual reminder for an overdue order', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 201, description: 'Reminder sent'), new OA\Response(response: 422, description: 'Order not overdue')])]
    public function remind(string $id, Request $request, OverdueOrderReminderService $service): JsonResponse
    {
        $order = PurchaseOrder::query()->findOrFail($id);
        $this->authorize('remind', $order);

        return response()->json(new PurchaseOrderReminderResource($service->remindOrder($order, $this->actorId($request))), 201);
    }

    #[OA\Get(path: '/api/v1/procurement/purchase-orders/{id}/reminders', operationId: 'procurementOrdersRemindersIndex', summary: 'Reminder log of a purchase order', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Reminder log')])]
    public function reminders(string $id): AnonymousResourceCollection
    {
        $order = PurchaseOrder::query()->findOrFail($id);
        $this->authorize('view', $order);

        return PurchaseOrderReminderResource::collection($order->reminders()->orderByDesc('sent_at')->get());
    }

    #[OA\Post(path: '/api/v1/procurement/purchase-orders/reminders/run', operationId: 'procurementOrdersRemindersRun', summary: 'Send the due automatic reminders now', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Number of reminders sent')])]
    public function runReminders(OverdueOrderReminderService $service): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        return response()->json(['sent' => $service->sendDue($this->tenantId())]);
    }

    #[OA\Post(path: '/api/v1/procurement/purchase-orders/pricing-preview', operationId: 'procurementOrdersPricingPreview', summary: 'Preview the pricing of a purchase line', description: 'Cascading discounts, net amounts, base-unit quantity and price, and the amount in company currency.', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Computed amounts as decimal strings')])]
    public function pricingPreview(PurchasePricingPreviewRequest $request, PurchasePricingService $pricing): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $data = $request->validated();
        $quantity = Decimal::of($data['quantity'], 4);
        $price = Decimal::of($data['unit_price'], 4);
        $steps = $pricing->normalizeCascade($data['discount_cascade'] ?? null);
        $factor = $pricing->conversionFactor($data['uom_conversion_factor'] ?? null);
        $rate = Decimal::of($data['exchange_rate'] ?? '1', 6);
        $net = $pricing->lineNet($quantity, $price, $steps);
        $tax = Decimal::round(bcdiv(bcmul($net, Decimal::of($data['tax_rate'] ?? '0', 2), 8), '100', 8), 2);
        $netUnit = $pricing->netUnitPrice($price, $steps);

        return response()->json(['data' => [
            'discount_cascade' => $steps,
            'effective_discount_percent' => $pricing->effectivePercent($steps),
            'net_unit_price' => $netUnit,
            'line_net_amount' => $net,
            'line_tax_amount' => $tax,
            'line_total_amount' => Decimal::add($net, $tax, 2),
            'quantity_base' => $pricing->toBase($quantity, $factor),
            'net_price_per_base_unit' => $pricing->pricePerBaseUnit($netUnit, $factor),
            'line_net_amount_company_currency' => $pricing->toBaseCurrency($net, $rate),
        ]]);
    }

    #[OA\Get(path: '/api/v1/procurement/dropshipments', operationId: 'procurementDropshipmentsIndex', summary: 'Dropshipments', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Paginated dropshipments')])]
    public function dropshipments(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrderDropship::query()->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('sales_order_id')) {
            $query->where('sales_order_id', (string) $request->query('sales_order_id'));
        }

        return PurchaseOrderDropshipResource::collection($query->paginate($this->perPage($request)));
    }

    #[OA\Get(path: '/api/v1/procurement/dropshipments/{id}', operationId: 'procurementDropshipmentsShow', summary: 'A dropshipment', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Dropshipment with virtual stock movements')])]
    public function showDropship(string $id): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        return response()->json(new PurchaseOrderDropshipResource(PurchaseOrderDropship::query()->with('movements')->findOrFail($id)));
    }

    #[OA\Post(path: '/api/v1/procurement/dropshipments', operationId: 'procurementDropshipmentsStore', summary: 'Create the supplier order of a dropship sales order', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 201, description: 'Dropshipment and purchase order created'), new OA\Response(response: 422, description: 'Not a dropship sales order or line without price')])]
    public function storeDropship(CreateDropshipmentRequest $request, DropshipmentService $service): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $data = $request->validated();

        $dropship = $service->createFromSalesOrder($data['sales_order_id'], $data['supplier_id'], $data, $this->actorId($request));

        return response()->json(new PurchaseOrderDropshipResource($dropship), 201);
    }

    #[OA\Post(path: '/api/v1/procurement/dropshipments/{id}/ddt', operationId: 'procurementDropshipmentsDdt', summary: 'Issue the custom DDT of a dropshipment', description: 'Numbers the transport document with the header of our company, the supplier as origin and the customer as destination.', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Dropshipment with the DDT payload'), new OA\Response(response: 422, description: 'Order not yet with the supplier')])]
    public function issueDdt(string $id, IssueDropshipmentDdtRequest $request, DropshipmentService $service): JsonResponse
    {
        $dropship = PurchaseOrderDropship::query()->findOrFail($id);
        $this->authorize('dispatch', PurchaseOrder::query()->findOrFail($dropship->purchase_order_id));

        return response()->json(new PurchaseOrderDropshipResource($service->issueDdt($dropship, $request->validated())));
    }

    #[OA\Post(path: '/api/v1/procurement/dropshipments/{id}/deliveries', operationId: 'procurementDropshipmentsDeliveries', summary: 'Record the delivery notice of the supplier', description: 'Books the goods through the virtual warehouse, updates the received quantities and authorises the active invoicing of the customer.', tags: ['Procurement Fulfilment'], security: [['bearerAuth' => [], 'tenantHeader' => []]], responses: [new OA\Response(response: 200, description: 'Dropshipment with virtual stock movements and billing authorisation'), new OA\Response(response: 422, description: 'Quantity beyond the open quantity')])]
    public function recordDelivery(string $id, RecordDropshipmentDeliveryRequest $request, DropshipmentService $service): JsonResponse
    {
        $dropship = PurchaseOrderDropship::query()->findOrFail($id);
        $this->authorize('dispatch', PurchaseOrder::query()->findOrFail($dropship->purchase_order_id));

        return response()->json(new PurchaseOrderDropshipResource($service->recordDelivery($dropship, $request->validated())));
    }
}
