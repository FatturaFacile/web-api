<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\CreateSalesOrderAction;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Http\Requests\CreateSalesOrderRequest;
use Modules\Invoicing\Http\Resources\SalesOrderResource;
use Modules\Invoicing\Models\SalesOrder;

final class SalesOrderController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = SalesOrder::with(['items', 'holds'])->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->has('credit_status')) {
            $query->where('credit_status', (string) $request->query('credit_status'));
        }
        if ($request->has('contact_id')) {
            $query->where('contact_id', (string) $request->query('contact_id'));
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate((int) $request->query('per_page', 25));

        return response()->json(SalesOrderResource::collection($orders)->response()->getData(true));
    }

    public function store(
        CreateSalesOrderRequest $request,
        CreateSalesOrderAction $action,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        $autoSubmit = (bool) ($validated['auto_submit'] ?? true);
        unset($validated['items'], $validated['auto_submit']);

        $userId = (string) ($request->user()?->id ?? 'system');

        $data = array_merge($validated, [
            'tenant_id' => $tenantContext->getTenantId(),
            'created_by' => $userId,
        ]);

        $order = $action->execute($data, $items, $autoSubmit, $userId);

        return response()->json(new SalesOrderResource($order), 201);
    }

    public function show(string $id): JsonResponse
    {
        $order = SalesOrder::with(['items', 'holds'])->findOrFail($id);

        return response()->json(new SalesOrderResource($order));
    }

    public function approve(string $id, SalesOrderServiceInterface $orderService, Request $request): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);
        $userId = (string) ($request->user()?->id ?? 'system');

        $approved = $orderService->approveOrder($order, $userId);

        return response()->json(new SalesOrderResource($approved));
    }

    public function reserve(string $id, SalesOrderServiceInterface $orderService): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);
        $reserved = $orderService->reserveInventory($order);

        return response()->json(new SalesOrderResource($reserved));
    }

    public function cancel(string $id, Request $request, SalesOrderServiceInterface $orderService): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);
        $reason = (string) $request->input('reason', 'Cancelled by user');
        $userId = (string) ($request->user()?->id ?? 'system');

        $cancelled = $orderService->cancelOrder($order, $reason, $userId);

        return response()->json(new SalesOrderResource($cancelled));
    }
}
