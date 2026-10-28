<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\CreateDeliveryNoteAction;
use Modules\Invoicing\Actions\DispatchGoodsAction;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Http\Requests\CreateDeliveryNoteRequest;
use Modules\Invoicing\Http\Resources\SalesDeliveryNoteResource;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder;

final class DeliveryNoteController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesDeliveryNote::with('items')->where('tenant_id', $tenantId);

        if ($request->has('shipping_status')) {
            $query->where('shipping_status', (string) $request->query('shipping_status'));
        }
        if ($request->has('sales_order_id')) {
            $query->where('sales_order_id', (string) $request->query('sales_order_id'));
        }

        $notes = $query->orderBy('created_at', 'desc')->paginate((int) $request->query('per_page', 25));

        return response()->json(SalesDeliveryNoteResource::collection($notes)->response()->getData(true));
    }

    public function store(
        CreateDeliveryNoteRequest $request,
        CreateDeliveryNoteAction $action
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        $orderId = $validated['sales_order_id'];
        unset($validated['items']);

        $order = SalesOrder::findOrFail($orderId);
        $deliveryNote = $action->execute($order, $validated, $items);

        return response()->json(new SalesDeliveryNoteResource($deliveryNote), 201);
    }

    public function show(string $id): JsonResponse
    {
        $deliveryNote = SalesDeliveryNote::with('items')->findOrFail($id);

        return response()->json(new SalesDeliveryNoteResource($deliveryNote));
    }

    public function dispatch(string $id, DispatchGoodsAction $action, Request $request): JsonResponse
    {
        $deliveryNote = SalesDeliveryNote::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $dispatched = $action->execute($deliveryNote, $userId);

        return response()->json(new SalesDeliveryNoteResource($dispatched));
    }

    public function confirm(string $id, DeliveryFulfillmentServiceInterface $service, Request $request): JsonResponse
    {
        $deliveryNote = SalesDeliveryNote::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $confirmed = $service->confirmDelivery($deliveryNote, $userId);

        return response()->json(new SalesDeliveryNoteResource($confirmed));
    }
}
