<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\ReceiveSubcontractRequest;
use Modules\Manufacturing\Http\Requests\SendSubcontractRequest;
use Modules\Manufacturing\Http\Resources\MfgSubcontractOrderResource;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgSubcontractOrder;
use Modules\Manufacturing\Services\SubcontractingService;
use OpenApi\Attributes as OA;

/**
 * Subcontracting: components sent to a subcontractor for a subcontracted operation, processed quantity received back,
 * cancellation with return of the components.
 */
final class SubcontractController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly SubcontractingService $subcontracting) {}

    /**
     * Paginated subcontract orders.
     */
    #[OA\Get(path: '/api/v1/manufacturing/subcontract-orders', operationId: 'listMfgSubcontractOrders', description: 'Paginated subcontract orders of the active company, filtered by status and production order.', summary: 'List subcontract orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Subcontracting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(name: 'production_order_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Subcontract orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgSubcontractOrder'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgSubcontractOrder::query()->with('lines')->where('tenant_id', $this->tenantId());
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('production_order_id')) {
            $query->where('production_order_id', (string) $request->query('production_order_id'));
        }

        return MfgSubcontractOrderResource::collection($query->latest()->gridPaginate($this->perPage($request)));
    }

    /**
     * Sends components to a subcontractor.
     */
    #[OA\Post(path: '/api/v1/manufacturing/production-orders/{id}/subcontract-orders', operationId: 'sendMfgSubcontractOrder', description: "Sends the components of a subcontracted operation (those tied to the operation in proportion to the quantity, or the ones listed) from the order warehouse to the subcontractor's warehouse: a stock transfer, no ledger entry.", summary: 'Send to subcontractor', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Subcontracting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgSendSubcontractRequest')),
        responses: [new OA\Response(response: 201, description: 'Subcontract order sent', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgSubcontractOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function send(SendSubcontractRequest $request, string $id): JsonResponse
    {
        $order = $this->owned(MfgProductionOrder::class, $id);
        $this->authorize('execute', $order);
        /** @var array{production_order_operation_id: string, subcontractor_contact_id: string, subcontract_warehouse_id: string, source_warehouse_id?: string|null, quantity: string, unit_cost?: string|null, posting_date?: string|null, notes?: string|null, components?: list<array{line_id: string, quantity: string}>|null} $data */
        $data = $request->validated();

        return (new MfgSubcontractOrderResource($this->subcontracting->send($order, $data, $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * One subcontract order.
     */
    #[OA\Get(path: '/api/v1/manufacturing/subcontract-orders/{id}', operationId: 'showMfgSubcontractOrder', description: 'Returns a subcontract order of the active company with its components.', summary: 'Get subcontract order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Subcontracting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Subcontract order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgSubcontractOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgSubcontractOrderResource
    {
        return new MfgSubcontractOrderResource($this->owned(MfgSubcontractOrder::class, $id)->load('lines'));
    }

    /**
     * Receives processed quantity from the subcontractor.
     */
    #[OA\Post(path: '/api/v1/manufacturing/subcontract-orders/{id}/receive', operationId: 'receiveMfgSubcontractOrder', description: 'Receives processed quantity: the components sent are consumed in proportion (Dr WIP, Cr raw materials), the good quantity is accrued at the agreed unit cost (Dr WIP, Cr GR/IR, cleared by the supplier invoice in Procurement) and the operation is confirmed.', summary: 'Receive from subcontractor', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Subcontracting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgReceiveSubcontractRequest')),
        responses: [new OA\Response(response: 200, description: 'Processed quantity received', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgSubcontractOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function receive(ReceiveSubcontractRequest $request, string $id): MfgSubcontractOrderResource
    {
        $subcontract = $this->authorizedSubcontract($id);
        $data = $request->validated();

        return new MfgSubcontractOrderResource($this->subcontracting->receive($subcontract, (string) $data['good_quantity'], (string) ($data['scrap_quantity'] ?? '0'), $data['posting_date'] ?? null, $this->actor($request)));
    }

    /**
     * Cancels a subcontract order with nothing received.
     */
    #[OA\Post(path: '/api/v1/manufacturing/subcontract-orders/{id}/cancel', operationId: 'cancelMfgSubcontractOrder', description: 'Cancels a subcontract order with nothing received: the components come back to the source warehouse.', summary: 'Cancel subcontract order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Subcontracting'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Subcontract order cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgSubcontractOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function cancel(Request $request, string $id): MfgSubcontractOrderResource
    {
        return new MfgSubcontractOrderResource($this->subcontracting->cancel($this->authorizedSubcontract($id), $this->actor($request)));
    }

    /**
     * The subcontract order of the company, on an order the user may execute.
     */
    private function authorizedSubcontract(string $id): MfgSubcontractOrder
    {
        $subcontract = $this->owned(MfgSubcontractOrder::class, $id);
        $this->authorize('execute', $this->owned(MfgProductionOrder::class, (string) $subcontract->production_order_id));

        return $subcontract;
    }
}
