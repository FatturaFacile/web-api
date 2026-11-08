<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Contracts\ShopFloorServiceInterface;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\CompleteExecutionRequest;
use Modules\Manufacturing\Http\Requests\RecordDowntimeRequest;
use Modules\Manufacturing\Http\Requests\StartExecutionRequest;
use Modules\Manufacturing\Http\Resources\MfgShopFloorExecutionResource;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use OpenApi\Attributes as OA;

/**
 * Shop floor job cards: start on an operation, downtime, completion (which confirms the operation).
 */
final class ShopFloorController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly ShopFloorServiceInterface $shopFloor) {}

    /**
     * Paginated job cards.
     */
    #[OA\Get(path: '/api/v1/manufacturing/shop-floor/executions', operationId: 'listMfgShopFloorExecutions', description: 'Paginated job cards of the active company, filtered by status and production order.', summary: 'List job cards', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Shop Floor'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(name: 'production_order_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Job cards', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgShopFloorExecution'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgShopFloorExecution::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('production_order_id')) {
            $query->where('production_order_id', (string) $request->query('production_order_id'));
        }

        return MfgShopFloorExecutionResource::collection($query->latest('started_at')->paginate($this->perPage($request)));
    }

    /**
     * Starts a job card.
     */
    #[OA\Post(path: '/api/v1/manufacturing/shop-floor/executions', operationId: 'recordExecutionManufacturingShopFloor', description: 'Starts a job card on an operation of a released or in-progress order.', summary: 'Start job card', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Shop Floor'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgStartExecutionRequest')),
        responses: [new OA\Response(response: 201, description: 'Job card started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgShopFloorExecution')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function recordExecution(StartExecutionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $operation = $this->owned(MfgProductionOrderOperation::class, (string) $data['production_order_operation_id']);
        $this->authorize('execute', $this->owned(MfgProductionOrder::class, (string) $operation->production_order_id));

        return (new MfgShopFloorExecutionResource($this->shopFloor->startExecution($operation, $data['operator_id'] ?? null, $data['machine_id'] ?? null, $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * Completes a job card.
     */
    #[OA\Post(path: '/api/v1/manufacturing/shop-floor/executions/{id}/complete', operationId: 'completeExecutionManufacturingShopFloor', description: 'Completes a job card: the time from start to now less the downtime (less the setup minutes given) is confirmed as run time with the good and scrapped units; labour, overhead and backflush follow.', summary: 'Complete job card', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Shop Floor'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgCompleteExecutionRequest')),
        responses: [new OA\Response(response: 200, description: 'Job card completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgShopFloorExecution')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function completeExecution(CompleteExecutionRequest $request, string $id): MfgShopFloorExecutionResource
    {
        $execution = $this->authorizedExecution($id);
        $data = $request->validated();

        return new MfgShopFloorExecutionResource($this->shopFloor->completeExecution(
            $execution,
            (string) $data['good_units'],
            (string) ($data['scrap_units'] ?? '0'),
            $data['scrap_reason'] ?? null,
            (string) ($data['setup_minutes'] ?? '0'),
            isset($data['labor_minutes']) ? (string) $data['labor_minutes'] : null,
            (bool) ($data['is_final'] ?? false),
            $data['posting_date'] ?? null,
            $this->actor($request),
        ));
    }

    /**
     * Reports downtime on a job card.
     */
    #[OA\Post(path: '/api/v1/manufacturing/shop-floor/executions/{id}/downtime', operationId: 'recordDowntimeManufacturingShopFloor', description: 'Adds downtime with its reason to an active job card; it is the availability input of OEE.', summary: 'Report downtime', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Shop Floor'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgRecordDowntimeRequest')),
        responses: [new OA\Response(response: 200, description: 'Downtime recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgShopFloorExecution')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function recordDowntime(RecordDowntimeRequest $request, string $id): MfgShopFloorExecutionResource
    {
        $data = $request->validated();

        return new MfgShopFloorExecutionResource($this->shopFloor->recordDowntime($this->authorizedExecution($id), (string) $data['downtime_minutes'], (string) $data['reason']));
    }

    /**
     * The job card of the company, on an order the user may execute.
     */
    private function authorizedExecution(string $id): MfgShopFloorExecution
    {
        $execution = $this->owned(MfgShopFloorExecution::class, $id);
        $this->authorize('execute', $this->owned(MfgProductionOrder::class, (string) $execution->production_order_id));

        return $execution;
    }
}
