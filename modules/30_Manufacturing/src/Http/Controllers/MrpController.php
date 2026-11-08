<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\MarkSuggestionConvertedRequest;
use Modules\Manufacturing\Http\Requests\RunMrpRequest;
use Modules\Manufacturing\Http\Requests\StoreForecastRequest;
use Modules\Manufacturing\Http\Resources\MfgForecastResource;
use Modules\Manufacturing\Http\Resources\MfgMrpRunResource;
use Modules\Manufacturing\Http\Resources\MfgPlannedOrderResource;
use Modules\Manufacturing\Http\Resources\MfgProductionOrderResource;
use Modules\Manufacturing\Http\Resources\MfgShortageResource;
use Modules\Manufacturing\Models\MfgDemandForecast;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;
use OpenApi\Attributes as OA;

/**
 * MRP: runs, planned orders, shortages, conversion of make orders, purchase suggestions for Procurement, demand
 * forecasts.
 */
final class MrpController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly MrpServiceInterface $mrp) {}

    /**
     * Paginated MRP runs.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mrp/runs', operationId: 'listManufacturingMrpRuns', description: 'Paginated MRP runs of the active company, newest first.', summary: 'List MRP runs', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'MRP runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgMrpRun'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        return MfgMrpRunResource::collection(MfgMrpRun::query()->where('tenant_id', $this->tenantId())->latest('executed_at')->paginate($this->perPage($request)));
    }

    /**
     * Runs MRP.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mrp/runs', operationId: 'runManufacturingMrp', description: 'Runs MRP over the horizon: open sales order lines, forecasts (less the sales demand of the same month), safety stock and components of open and planned production orders are netted level by level against stock and scheduled receipts (open production orders, sent purchase orders). Earlier unconverted planned orders are superseded.', summary: 'Run MRP', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgRunMrpRequest')),
        responses: [new OA\Response(response: 201, description: 'Run completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgMrpRun')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function run(RunMrpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $run = $this->mrp->executeMrpRun(new RunMrpDTO(
            tenantId: $this->tenantId(),
            horizonStartDate: (string) $data['horizon_start_date'],
            horizonEndDate: (string) $data['horizon_end_date'],
            runType: (string) ($data['run_type'] ?? 'regenerative'),
            itemIds: isset($data['item_ids']) ? array_values(array_map('strval', $data['item_ids'])) : null,
            executedBy: $this->actor($request),
        ));

        return (new MfgMrpRunResource($run))->response()->setStatusCode(201);
    }

    /**
     * One MRP run with its results.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mrp/runs/{id}', operationId: 'showManufacturingMrpRun', description: 'Returns an MRP run of the active company with its planned orders and shortages.', summary: 'Get MRP run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'MRP run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgMrpRun')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgMrpRunResource
    {
        return new MfgMrpRunResource($this->owned(MfgMrpRun::class, $id)->load(['plannedOrders', 'shortages']));
    }

    /**
     * Paginated planned orders.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mrp/planned-orders', operationId: 'listManufacturingMrpPlannedOrders', description: 'Paginated planned orders of the active company, filtered by run, type and status.', summary: 'List planned orders', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(name: 'mrp_run_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'order_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['make', 'buy'])), new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Planned orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgPlannedOrder'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function plannedOrders(Request $request): AnonymousResourceCollection
    {
        $query = MfgMrpPlannedOrder::query()->where('tenant_id', $this->tenantId());
        foreach (['mrp_run_id', 'order_type', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }

        return MfgPlannedOrderResource::collection($query->orderBy('due_date')->orderBy('planned_order_number')->paginate($this->perPage($request)));
    }

    /**
     * Open purchase suggestions for Procurement.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mrp/purchase-suggestions', operationId: 'listManufacturingMrpPurchaseSuggestions', description: 'Open purchase suggestions (buy planned orders not converted yet) of the active company, by due date. Data only: Procurement converts them and records its document through the procurement-link endpoint.', summary: 'List purchase suggestions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Purchase suggestions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgPlannedOrder'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function purchaseSuggestions(Request $request): AnonymousResourceCollection
    {
        return MfgPlannedOrderResource::collection(MfgMrpPlannedOrder::query()->where('tenant_id', $this->tenantId())->where('order_type', 'buy')->where('status', 'planned')->orderBy('due_date')->paginate($this->perPage($request)));
    }

    /**
     * Paginated shortages.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mrp/shortages', operationId: 'listManufacturingMrpShortages', description: 'Paginated shortages of the active company, filtered by run.', summary: 'List shortages', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(name: 'mrp_run_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Shortages', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgShortage'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function shortages(Request $request): AnonymousResourceCollection
    {
        $query = MfgMrpShortage::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('mrp_run_id')) {
            $query->where('mrp_run_id', (string) $request->query('mrp_run_id'));
        }

        return MfgShortageResource::collection($query->orderBy('required_date')->paginate($this->perPage($request)));
    }

    /**
     * Converts a make planned order into a production order.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mrp/planned-orders/{id}/convert', operationId: 'convertManufacturingMrpPlannedOrder', description: 'Converts a make planned order into a planned production order with its dates, BOM and routing. Purchase suggestions are refused: Procurement converts them.', summary: 'Convert planned order', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 201, description: 'Production order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgProductionOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function convertPlannedOrder(Request $request, string $id): JsonResponse
    {
        $order = $this->mrp->convertPlannedOrder($this->owned(MfgMrpPlannedOrder::class, $id), $this->actor($request));

        return (new MfgProductionOrderResource($order))->response()->setStatusCode(201);
    }

    /**
     * Records the procurement document of a purchase suggestion.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mrp/planned-orders/{id}/procurement-link', operationId: 'linkManufacturingMrpPurchaseSuggestion', description: 'Records the purchase requisition or order Procurement converted the suggestion into; the suggestion leaves the open list.', summary: 'Link purchase suggestion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgProcurementLinkRequest')),
        responses: [new OA\Response(response: 200, description: 'Suggestion linked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgPlannedOrder')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function linkProcurement(MarkSuggestionConvertedRequest $request, string $id): MfgPlannedOrderResource
    {
        $data = $request->validated();

        return new MfgPlannedOrderResource($this->mrp->markPurchaseSuggestionConverted($this->owned(MfgMrpPlannedOrder::class, $id), (string) $data['procurement_document_type'], (string) $data['procurement_document_id'], $this->actor($request)));
    }

    /**
     * Paginated demand forecasts.
     */
    #[OA\Get(path: '/api/v1/manufacturing/mrp/forecasts', operationId: 'listManufacturingMrpForecasts', description: 'Paginated demand forecasts of the active company, filtered by item.', summary: 'List demand forecasts', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgItemFilter'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Forecasts', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgForecast'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function forecasts(Request $request): AnonymousResourceCollection
    {
        $query = MfgDemandForecast::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('mfg_item_id')) {
            $query->where('mfg_item_id', (string) $request->query('mfg_item_id'));
        }

        return MfgForecastResource::collection($query->orderBy('forecast_date')->paginate($this->perPage($request)));
    }

    /**
     * Creates a demand forecast.
     */
    #[OA\Post(path: '/api/v1/manufacturing/mrp/forecasts', operationId: 'storeManufacturingMrpForecast', description: 'Records an expected demand of an item on a date; MRP nets it less the sales demand of the same month.', summary: 'Create demand forecast', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgStoreForecastRequest')),
        responses: [new OA\Response(response: 201, description: 'Forecast created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgForecast')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function storeForecast(StoreForecastRequest $request): JsonResponse
    {
        $forecast = MfgDemandForecast::create([...$request->validated(), 'tenant_id' => $this->tenantId(), 'created_by' => $this->actor($request)]);

        return (new MfgForecastResource($forecast))->response()->setStatusCode(201);
    }

    /**
     * Deletes a demand forecast.
     */
    #[OA\Delete(path: '/api/v1/manufacturing/mrp/forecasts/{id}', operationId: 'deleteManufacturingMrpForecast', description: 'Deletes a demand forecast of the active company (the next MRP run no longer plans it).', summary: 'Delete demand forecast', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing MRP'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 204, description: 'Forecast deleted'), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function destroyForecast(string $id): Response
    {
        $this->owned(MfgDemandForecast::class, $id)->delete();

        return response()->noContent();
    }
}
