<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Inventory\Enums\DiscrepancyStatus;
use Modules\Inventory\Models\TransferDiscrepancy;
use Modules\Inventory\Models\TransferOrder;
use Modules\Logistics\Actions\ApproveStockTransferOrderAction;
use Modules\Logistics\Actions\CloseStockTransferOrderAction;
use Modules\Logistics\Actions\CreateStockTransferOrderAction;
use Modules\Logistics\Actions\ReceiveStockTransferOrderAction;
use Modules\Logistics\Actions\ResolveTransferDiscrepancyAction;
use Modules\Logistics\Actions\ShipStockTransferOrderAction;
use Modules\Logistics\Http\Requests\ReceiveTransferOrderRequest;
use Modules\Logistics\Http\Requests\ResolveTransferDiscrepancyRequest;
use Modules\Logistics\Http\Requests\ScanTransferLineRequest;
use Modules\Logistics\Http\Requests\ShipTransferOrderRequest;
use Modules\Logistics\Http\Requests\StoreTransferOrderRequest;
use Modules\Logistics\Http\Resources\TransferOrderResource;
use Modules\Logistics\Services\StockTransferService;
use Modules\Logistics\Support\LogisticsMessage;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'WMS Transfers', description: 'Two-step stock transfers (draft, approved, shipped, received, closed) and discrepancy approval. Also served under the legacy prefix /api/v1/logistics.')]
final class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $service) {}

    #[OA\Get(
        path: '/api/v1/wms/transfers',
        summary: 'List transfer orders',
        description: 'Filters: status, purpose, is_inter_company, warehouse_id (origin or destination), direction (inbound|outbound, needs warehouse_id). Requires permission logistics.transfers.manage.',
        tags: ['WMS Transfers'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'purpose', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['inbound', 'outbound'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 403, description: 'Missing logistics permission'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->query('warehouse_id');
        $direction = $request->query('direction');

        $query = TransferOrder::query()->with('lines.item')
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', (string) $v)))
            ->when($request->query('purpose'), fn ($q, $v) => $q->where('purpose', $v))
            ->when($request->boolean('is_inter_company'), fn ($q) => $q->where('is_inter_company', true))
            ->when($warehouseId !== null, fn ($q) => $q->where(function ($w) use ($warehouseId, $direction): void {
                match ($direction) {
                    'inbound' => $w->where('destination_warehouse_id', $warehouseId),
                    'outbound' => $w->where('source_warehouse_id', $warehouseId),
                    default => $w->where('destination_warehouse_id', $warehouseId)->orWhere('source_warehouse_id', $warehouseId),
                };
            }))
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25)))->through(fn (TransferOrder $order): array => TransferOrderResource::make($order)->resolve()));
    }

    #[OA\Get(
        path: '/api/v1/wms/transfers/{id}',
        summary: 'Get a transfer order with lines and discrepancies',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->one(TransferOrder::query()->findOrFail($id));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers',
        summary: 'Create a transfer order (draft)',
        description: 'Purposes: inter_warehouse, technician_van, inter_company, customer_consignment, consignment_return, subcontracting, subcontracting_return. Requires permission logistics.transfers.manage.',
        tags: ['WMS Transfers'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TransferInput')),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function store(StoreTransferOrderRequest $request, CreateStockTransferOrderAction $action): JsonResponse
    {
        return $this->run(fn () => $this->one($action->execute($request->validated()), 201));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers/{id}/approve',
        summary: 'Approve a draft transfer (manager sign-off before dispatch)',
        description: 'Requires permission logistics.transfers.approve.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Business rule error'),
        ]
    )]
    public function approve(Request $request, string $id, ApproveStockTransferOrderAction $action): JsonResponse
    {
        return $this->run(fn () => $this->one($action->execute($id, $request->user() !== null ? (string) $request->user()->id : null)));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers/{id}/ship',
        summary: 'Step 1 dispatch: origin to in-transit, fiscal DDT and inter-company invoice draft',
        description: 'Requires permission logistics.transfers.manage and an approved transfer.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function ship(ShipTransferOrderRequest $request, string $id, ShipStockTransferOrderAction $action): JsonResponse
    {
        return $this->run(fn () => $this->one($action->execute($id, $request->validated())));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers/{id}/receive',
        summary: 'Step 2 receipt: in-transit to destination; shortages and damages raise discrepancies',
        description: 'Per line received_qty (good) and damaged_qty. Send final=false for a partial receipt while the rest is still on its way. Requires permission logistics.transfers.manage.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/TransferReceiptInput')),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function receive(ReceiveTransferOrderRequest $request, string $id, ReceiveStockTransferOrderAction $action): JsonResponse
    {
        $data = $request->validated();

        return $this->run(fn () => $this->one($action->execute($id, $data['lines'] ?? [], (bool) ($data['final'] ?? true))));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers/{id}/close',
        summary: 'Close a fully received transfer',
        description: 'Requires permission logistics.transfers.approve.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Business rule error'),
        ]
    )]
    public function close(string $id, CloseStockTransferOrderAction $action): JsonResponse
    {
        return $this->run(fn () => $this->one($action->execute($id)));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers/{id}/cancel',
        summary: 'Cancel a draft or approved transfer',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Business rule error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        return $this->run(fn () => $this->one($this->service->cancel(TransferOrder::query()->findOrFail($id))));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers/{id}/scan',
        summary: 'Resolve a scanned barcode / SKU to a transfer line (warehouse floor dispatch and receipt)',
        description: 'Returns the matching line with its outstanding quantity. Requires permission logistics.transfers.manage.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'code', type: 'string')], type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Matching line'),
            new OA\Response(response: 422, description: 'Code does not belong to this transfer'),
        ]
    )]
    public function scan(ScanTransferLineRequest $request, string $id): JsonResponse
    {
        $order = TransferOrder::query()->with('lines.item')->findOrFail($id);
        $code = (string) $request->validated('code');

        $products = Product::query()
            ->whereIn('id', $order->lines->map(fn ($l) => $l->item?->product_id)->filter()->values())
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->pluck('id');
        $line = $order->lines->first(fn ($l): bool => $l->item?->product_id !== null && $products->contains($l->item->product_id));
        if ($line === null) {
            return response()->json(['message' => LogisticsMessage::get('LOGISTICS.ERRORS.LINE_NOT_IN_TRANSFER', ['line' => $code])], 422);
        }

        return response()->json(['data' => [
            'line' => TransferOrderResource::line($line),
            'outstanding_qty' => round((float) $line->quantity_shipped - (float) $line->quantity_received - (float) $line->quantity_damaged, 4),
        ]]);
    }

    #[OA\Get(
        path: '/api/v1/wms/transfer-discrepancies',
        summary: 'List transfer discrepancies (shortage / damage)',
        description: 'Requires permission logistics.transfers.manage.',
        tags: ['WMS Transfers'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Success')]
    )]
    public function discrepancies(Request $request): JsonResponse
    {
        $query = TransferDiscrepancy::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at');

        return response()->json($query->paginate(min(100, (int) $request->query('per_page', 25)))->through(fn (TransferDiscrepancy $d): array => TransferOrderResource::discrepancy($d)));
    }

    #[OA\Post(
        path: '/api/v1/wms/transfers/{id}/discrepancies/{discrepancyId}/resolve',
        summary: 'Manager approval of a discrepancy: write-off, return to origin or carrier claim',
        description: 'Requires permission logistics.transfers.approve. Closes the transfer when the last discrepancy is resolved.',
        tags: ['WMS Transfers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'discrepancyId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DiscrepancyResolutionInput')),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation or business rule error'),
        ]
    )]
    public function resolveTransferDiscrepancy(ResolveTransferDiscrepancyRequest $request, string $id, string $discrepancyId, ResolveTransferDiscrepancyAction $action): JsonResponse
    {
        return $this->resolveWith($request, $discrepancyId, $action, $id);
    }

    /** Legacy flat route: /transfer-discrepancies/{id}/resolve. */
    public function resolveDiscrepancy(ResolveTransferDiscrepancyRequest $request, string $id, ResolveTransferDiscrepancyAction $action): JsonResponse
    {
        return $this->resolveWith($request, $id, $action, null);
    }

    private function resolveWith(ResolveTransferDiscrepancyRequest $request, string $discrepancyId, ResolveTransferDiscrepancyAction $action, ?string $transferId): JsonResponse
    {
        $resolution = DiscrepancyStatus::from((string) $request->validated('resolution'));

        return $this->run(fn () => response()->json(['data' => TransferOrderResource::discrepancy($action->execute(
            $discrepancyId,
            $resolution,
            $request->user() !== null ? (string) $request->user()->id : null,
            $transferId,
        ))]));
    }

    private function one(TransferOrder $order, int $status = 200): JsonResponse
    {
        return response()->json(['data' => TransferOrderResource::make($order->load(['lines.item', 'discrepancies']))->resolve()], $status);
    }

    /**
     * @param  callable(): JsonResponse  $callback
     */
    private function run(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
