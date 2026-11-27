<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Distribution\Http\Controllers\Concerns\InteractsWithDistribution;
use Modules\Distribution\Http\Requests\DispatchTransferRequest;
use Modules\Distribution\Http\Requests\DistributionFilterRequest;
use Modules\Distribution\Http\Requests\ReceiveTransferRequest;
use Modules\Distribution\Http\Requests\StoreTransferRequest;
use Modules\Distribution\Http\Resources\ArrayResource;
use Modules\Distribution\Models\TransferMeta;
use Modules\Distribution\Services\InTransitService;
use Modules\Distribution\Services\StockPositionService;
use Modules\Distribution\Services\TransferPresenter;
use Modules\Distribution\Services\TransferWorkflowService;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use OpenApi\Attributes as OA;

/**
 * Inter-warehouse transfers with stock in transit: list, create, approve, dispatch, receive (with discrepancy resolution),
 * cancel, the source availability preview and the goods in transit view.
 */
final class TransferController extends Controller
{
    use ApiResponse;
    use InteractsWithDistribution;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TransferWorkflowService $workflow,
        private readonly TransferPresenter $presenter,
    ) {}

    #[OA\Get(path: '/api/v1/distribution/transfers', operationId: 'distributionListTransfers', summary: 'List the warehouse transfers with the status counts', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Transfers, paginated')])]
    public function index(DistributionFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', TransferMeta::class);

        $filtered = static fn ($rows) => $rows
            ->when($request->input('warehouse_id'), static fn ($inner, $id) => $inner->where(static fn ($either) => $either->where('source_warehouse_id', $id)->orWhere('destination_warehouse_id', $id)))
            ->when($request->input('q'), static fn ($inner, $term) => $inner->whereRaw('LOWER(transfer_number) LIKE ?', ['%'.mb_strtolower((string) $term).'%']));

        $counts = $filtered(TransferOrder::query())->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(static fn ($total): int => (int) $total)->all();

        $page = $filtered(TransferOrder::query())
            ->when($request->input('status'), static fn ($rows, $status) => $rows->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($this->perPage(25));

        $items = $this->presenter->orders(collect($page->items()));

        return $this->successResponse(ArrayResource::collection($items), $this->message('listed'), 200, [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
            'status_counts' => $counts,
        ]);
    }

    #[OA\Get(path: '/api/v1/distribution/transfers/{id}', operationId: 'distributionShowTransfer', summary: 'Get a warehouse transfer', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Transfer')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('viewAny', TransferMeta::class);

        return $this->respond(TransferOrder::query()->findOrFail($id), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/distribution/transfers', operationId: 'distributionCreateTransfer', summary: 'Create a transfer between two warehouses', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 201, description: 'Transfer created')])]
    public function store(StoreTransferRequest $request): JsonResponse
    {
        Gate::authorize('create', TransferMeta::class);

        $order = $this->workflow->create(
            Warehouse::query()->findOrFail($request->string('source_warehouse_id')->value()),
            Warehouse::query()->findOrFail($request->string('destination_warehouse_id')->value()),
            array_values((array) $request->input('lines')),
            $this->userId(),
            $request->input('requested_date'),
            $request->input('notes'),
            $request->boolean('auto_approve'),
        );

        return $this->respond($order, $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/distribution/transfers/{id}/approve', operationId: 'distributionApproveTransfer', summary: 'Approve a draft transfer', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Transfer approved')])]
    public function approve(string $id): JsonResponse
    {
        Gate::authorize('create', TransferMeta::class);

        return $this->respond($this->workflow->approve(TransferOrder::query()->findOrFail($id), $this->userId()), $this->message('transfer_approved'));
    }

    #[OA\Post(path: '/api/v1/distribution/transfers/{id}/dispatch', operationId: 'distributionDispatchTransfer', summary: 'Dispatch a transfer: stock goes in transit', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Transfer dispatched')])]
    public function dispatch(string $id, DispatchTransferRequest $request): JsonResponse
    {
        Gate::authorize('create', TransferMeta::class);

        return $this->respond($this->workflow->dispatch(TransferOrder::query()->findOrFail($id), $this->userId(), $request->validated()), $this->message('transfer_dispatched'));
    }

    #[OA\Post(path: '/api/v1/distribution/transfers/{id}/receive', operationId: 'distributionReceiveTransfer', summary: 'Receive a transfer with the discrepancy resolution', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Transfer received')])]
    public function receive(string $id, ReceiveTransferRequest $request): JsonResponse
    {
        Gate::authorize('create', TransferMeta::class);

        $order = $this->workflow->receive(
            TransferOrder::query()->findOrFail($id),
            array_values((array) $request->input('lines', [])),
            $this->userId(),
            $request->boolean('final'),
            $request->input('resolution'),
        );

        return $this->respond($order, $this->message('transfer_received'));
    }

    #[OA\Post(path: '/api/v1/distribution/transfers/{id}/cancel', operationId: 'distributionCancelTransfer', summary: 'Cancel a transfer that did not ship yet', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'Transfer cancelled')])]
    public function cancel(string $id): JsonResponse
    {
        Gate::authorize('create', TransferMeta::class);

        return $this->respond($this->workflow->cancel(TransferOrder::query()->findOrFail($id)), $this->message('transfer_cancelled'));
    }

    #[OA\Get(path: '/api/v1/distribution/availability', operationId: 'distributionAvailability', summary: 'Stock of an item in a warehouse (source preview)', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'On hand, reserved, available and in transit inbound')])]
    public function availability(DistributionFilterRequest $request, StockPositionService $positions): JsonResponse
    {
        Gate::authorize('viewAny', TransferMeta::class);
        abort_unless($request->filled('warehouse_id') && $request->filled('stock_item_id'), 422, 'warehouse_id and stock_item_id are required');

        return $this->successResponse($positions->snapshot((string) $request->input('warehouse_id'), (string) $request->input('stock_item_id')), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/distribution/in-transit', operationId: 'distributionInTransit', summary: 'Goods in transit by item, route, lot and arrival', security: self::SECURITY, tags: ['Distribution'], responses: [new OA\Response(response: 200, description: 'In-transit lines with totals')])]
    public function inTransit(DistributionFilterRequest $request, InTransitService $inTransit): JsonResponse
    {
        Gate::authorize('viewAny', TransferMeta::class);

        $result = $inTransit->lines([
            'stock_item_id' => $request->input('stock_item_id'),
            'source_warehouse_id' => $request->input('source_warehouse_id'),
            'destination_warehouse_id' => $request->input('destination_warehouse_id'),
            'lot_id' => $request->input('lot_id'),
            'overdue' => $request->boolean('overdue'),
            'expected_to' => $request->input('expected_to'),
        ]);

        return $this->successResponse(ArrayResource::collection($result['lines']), $this->message('listed'), 200, [
            'total' => count($result['lines']), 'total_units' => $result['total_units'], 'total_value' => $result['total_value'],
        ]);
    }

    private function respond(TransferOrder $order, string $message, int $status = 200): JsonResponse
    {
        $shape = $this->presenter->orders(collect([TransferOrder::query()->findOrFail($order->id)]))[0];

        return $this->successResponse(new ArrayResource($shape), $message, $status);
    }
}
