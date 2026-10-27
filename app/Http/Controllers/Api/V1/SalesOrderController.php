<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConvertOrderToDeliveryNote;
use App\Actions\Sales\ConvertToInvoice;
use App\Actions\Sales\SaveSalesDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ConvertToDeliveryNoteRequest;
use App\Http\Requests\Sales\DepositInvoiceRequest;
use App\Http\Requests\Sales\StoreSalesOrderRequest;
use App\Http\Resources\DeliveryNoteResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SalesOrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SalesOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaveSalesDocument $saveDocument,
        private readonly ConvertOrderToDeliveryNote $toDeliveryNote,
        private readonly ConvertToInvoice $toInvoice,
    ) {}

    /**
     * List paginated sales orders with optional status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesOrder::with('contact')->latest('date');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $orders = $query->paginate($perPage);

        return $this->successResponse(
            data: SalesOrderResource::collection($orders),
            message: __('sales.messages.orders_list'),
            meta: ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total()]
        );
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $order = $this->saveDocument->create(SalesOrder::class, 'sales_order', 'order_number', $request->validated());

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.order_created'), Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $order = SalesOrder::with(['contact', 'items'])->findOrFail($id);

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.order_retrieved'));
    }

    public function update(StoreSalesOrderRequest $request, string $id): JsonResponse
    {
        $order = $this->saveDocument->update(SalesOrder::findOrFail($id), $request->validated());

        return $this->successResponse(new SalesOrderResource($order), __('sales.messages.order_updated'));
    }

    public function destroy(string $id): JsonResponse
    {
        SalesOrder::findOrFail($id)->delete();

        return $this->successResponse(null, __('sales.messages.order_deleted'));
    }

    /**
     * Create a (possibly partial) delivery note from the order.
     */
    public function createDeliveryNote(ConvertToDeliveryNoteRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $lines = $validated['lines'] ?? [];
        unset($validated['lines']);

        $note = $this->toDeliveryNote->execute(SalesOrder::findOrFail($id), $lines, $validated);

        return $this->successResponse(new DeliveryNoteResource($note), __('sales.messages.order_delivery_note'), Response::HTTP_CREATED);
    }

    /**
     * Invoice the whole order.
     */
    public function createInvoice(string $id): JsonResponse
    {
        $invoice = $this->toInvoice->fromOrder(SalesOrder::with('items')->findOrFail($id));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.order_invoiced'), Response::HTTP_CREATED);
    }

    /**
     * Issue a deposit invoice for a percentage of the order.
     */
    public function createDepositInvoice(DepositInvoiceRequest $request, string $id): JsonResponse
    {
        $invoice = $this->toInvoice->depositFromOrder(SalesOrder::with('items')->findOrFail($id), (float) $request->validated('percentage'));

        return $this->successResponse(new InvoiceResource($invoice), __('sales.messages.deposit_invoiced'), Response::HTTP_CREATED);
    }
}
