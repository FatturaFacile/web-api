<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\DTOs\ConfirmOperationDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\ConfirmOperationRequest;
use Modules\Manufacturing\Http\Requests\IssueMaterialsRequest;
use Modules\Manufacturing\Http\Requests\ReceiveOutputRequest;
use Modules\Manufacturing\Http\Resources\MfgMaterialMovementResource;
use Modules\Manufacturing\Http\Resources\MfgOperationConfirmationResource;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgOperationConfirmation;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use OpenApi\Attributes as OA;

/**
 * Execution of production orders on stock and ledger: component issues, operation confirmations, output receipts, the
 * transactions of an order and its cost report.
 */
final class ProductionExecutionController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(
        private readonly ProductionExecutionServiceInterface $execution,
        private readonly ProductionCostingServiceInterface $costing,
    ) {}

    /**
     * Issues components to an order.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/issues', operationId: 'issueMfgProductionOrderMaterials', description: 'Issues components from the order warehouse (inventory movements, refused beyond available stock) and posts them to WIP at their inventory valuation. Quantities above the open quantity need allow_over_issue.', summary: 'Issue components', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgIssueMaterialsRequest')),
        responses: [new OA\Response(response: 201, description: 'Components issued', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgMaterialMovement'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function issue(IssueMaterialsRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        /** @var list<array{line_id: string, quantity: string, lot_id?: string|null}> $lines */
        $lines = array_map(static fn (array $line): array => ['line_id' => (string) $line['line_id'], 'quantity' => (string) $line['quantity'], 'lot_id' => $line['lot_id'] ?? null], array_values($data['lines']));
        $movements = $this->execution->issueMaterials($this->executable($id), $lines, $data['posting_date'] ?? null, (bool) ($data['allow_over_issue'] ?? false), $this->actor($request));

        return MfgMaterialMovementResource::collection($movements)->response()->setStatusCode(201);
    }

    /**
     * Confirms an operation.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/operations/{operationId}/confirmations', operationId: 'confirmMfgProductionOrderOperation', description: 'Confirms actual setup, run and labour minutes, good and scrapped quantities of an operation: labour (labour minutes at the labour rate) and overhead (machine minutes at the overhead rate) are absorbed into WIP, the backflushed components of the operation are consumed.', summary: 'Confirm operation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId'), new OA\Parameter(ref: '#/components/parameters/MfgOperationId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgConfirmOperationRequest')),
        responses: [new OA\Response(response: 201, description: 'Operation confirmed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgOperationConfirmation')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function confirm(ConfirmOperationRequest $request, string $id, string $operationId): JsonResponse
    {
        $order = $this->executable($id);
        $operation = MfgProductionOrderOperation::query()->where('production_order_id', $order->id)->findOrFail($operationId);
        $data = $request->validated();

        $confirmation = $this->execution->confirmOperation($order, $operation, new ConfirmOperationDTO(
            setupMinutes: (string) ($data['setup_minutes'] ?? '0'),
            runMinutes: (string) ($data['run_minutes'] ?? '0'),
            laborMinutes: isset($data['labor_minutes']) ? (string) $data['labor_minutes'] : null,
            goodQuantity: (string) ($data['good_quantity'] ?? '0'),
            scrapQuantity: (string) ($data['scrap_quantity'] ?? '0'),
            scrapReason: $data['scrap_reason'] ?? null,
            isFinal: (bool) ($data['is_final'] ?? false),
            postingDate: $data['posting_date'] ?? null,
            downtimeMinutes: (string) ($data['downtime_minutes'] ?? '0'),
        ), $this->actor($request));

        return (new MfgOperationConfirmationResource($confirmation))->response()->setStatusCode(201);
    }

    /**
     * Receives output.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/receipts', operationId: 'receiveMfgProductionOrderOutput', description: 'Receives output into stock at the standard unit cost of the order (lot number for lot-tracked items, one serial number per unit for serialized ones; the component lots consumed are linked to the output lot), posts it from WIP to finished goods and backflushes the components not tied to an operation. At most the open quantity of the order.', summary: 'Receive output', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgReceiveOutputRequest')),
        responses: [new OA\Response(response: 201, description: 'Output received', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgMaterialMovement')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function receive(ReceiveOutputRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        /** @var list<string> $serials */
        $serials = array_values(array_map('strval', $data['serial_numbers'] ?? []));
        $movement = $this->execution->receiveOutput($this->executable($id), (string) $data['quantity'], $data['lot_number'] ?? null, $serials, $data['warehouse_id'] ?? null, $data['posting_date'] ?? null, $this->actor($request));

        return (new MfgMaterialMovementResource($movement))->response()->setStatusCode(201);
    }

    /**
     * Material movements and confirmations of an order.
     */
    #[OA\Get(path: '/api/v1/manufacturing/production-orders/{id}/transactions', operationId: 'listMfgProductionOrderTransactions', description: 'Material movements (issues, backflush, receipts, subcontracting) and operation confirmations of the order, oldest first.', summary: 'Production order transactions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Transactions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'material_movements', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgMaterialMovement')), new OA\Property(property: 'confirmations', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgOperationConfirmation'))], type: 'object')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function transactions(string $id): JsonResponse
    {
        $order = $this->owned(MfgProductionOrder::class, $id);

        return response()->json(['data' => [
            'material_movements' => MfgMaterialMovementResource::collection(MfgMaterialMovement::query()->where('production_order_id', $order->id)->orderBy('created_at')->orderBy('id')->get()),
            'confirmations' => MfgOperationConfirmationResource::collection(MfgOperationConfirmation::query()->where('production_order_id', $order->id)->orderBy('confirmed_at')->orderBy('id')->get()),
        ]]);
    }

    /**
     * Cost report of an order.
     */
    #[OA\Get(path: '/api/v1/manufacturing/production-orders/{id}/cost-rollup', operationId: 'getCostRollupManufacturingProductionOrder', description: 'Standard against actual cost per element (material, labour, overhead, subcontracting): standard for the planned quantity and for the output received, actual charged to WIP, variance (live until the close, then the settled one), WIP balance and the journal entries of the order.', summary: 'Production order cost report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Production Orders'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Cost report', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgCostReport')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function costs(string $id): JsonResponse
    {
        return response()->json(['data' => $this->costing->costReport($this->owned(MfgProductionOrder::class, $id))]);
    }

    /**
     * The order of the company the user may execute.
     */
    private function executable(string $id): MfgProductionOrder
    {
        $order = $this->owned(MfgProductionOrder::class, $id);
        $this->authorize('execute', $order);

        return $order;
    }
}
