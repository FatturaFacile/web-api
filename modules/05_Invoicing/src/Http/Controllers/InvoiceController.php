<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\CreateInvoiceFromOrderAction;
use Modules\Invoicing\Actions\PostInvoiceAction;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Http\Requests\CreateInvoiceRequest;
use Modules\Invoicing\Http\Resources\SalesInvoiceResource;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;

final class InvoiceController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesInvoice::with('items')->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->has('payment_status')) {
            $query->where('payment_status', (string) $request->query('payment_status'));
        }
        if ($request->has('contact_id')) {
            $query->where('contact_id', (string) $request->query('contact_id'));
        }
        if ($request->has('invoice_type')) {
            $query->where('invoice_type', (string) $request->query('invoice_type'));
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate((int) $request->query('per_page', 25));

        return response()->json(SalesInvoiceResource::collection($invoices)->response()->getData(true));
    }

    public function store(
        CreateInvoiceRequest $request,
        InvoicingServiceInterface $service,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        unset($validated['items']);

        $data = array_merge($validated, [
            'tenant_id' => $tenantContext->getTenantId(),
            'created_by' => $request->user()?->id,
        ]);

        $invoice = $service->createInvoice($data, $items);

        return response()->json(new SalesInvoiceResource($invoice), 201);
    }

    public function fromOrder(
        string $orderId,
        CreateInvoiceFromOrderAction $action,
        Request $request
    ): JsonResponse {
        $order = SalesOrder::findOrFail($orderId);
        $invoice = $action->execute($order, $request->except(['items']));

        return response()->json(new SalesInvoiceResource($invoice), 201);
    }

    public function fromDeliveryNote(
        string $deliveryNoteId,
        InvoicingServiceInterface $service,
        Request $request
    ): JsonResponse {
        $deliveryNote = SalesDeliveryNote::findOrFail($deliveryNoteId);
        $invoice = $service->createInvoiceFromDeliveryNote($deliveryNote, $request->except(['items']));

        return response()->json(new SalesInvoiceResource($invoice), 201);
    }

    public function show(string $id): JsonResponse
    {
        $invoice = SalesInvoice::with('items')->findOrFail($id);

        return response()->json(new SalesInvoiceResource($invoice));
    }

    public function approve(string $id, InvoicingServiceInterface $service, Request $request): JsonResponse
    {
        $invoice = SalesInvoice::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $approved = $service->approveInvoice($invoice, $userId);

        return response()->json(new SalesInvoiceResource($approved));
    }

    public function post(string $id, PostInvoiceAction $action, Request $request): JsonResponse
    {
        $invoice = SalesInvoice::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $posted = $action->execute($invoice, $userId);

        return response()->json(new SalesInvoiceResource($posted));
    }
}
