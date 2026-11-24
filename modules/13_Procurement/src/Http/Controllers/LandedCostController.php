<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\StoreLandedCostRequest;
use Modules\Procurement\Http\Resources\LandedCostResource;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Services\LandedCostService;
use OpenApi\Attributes as OA;

/**
 * Landed costs (freight, duty, insurance...) allocated to goods receipts and capitalized into the inventory value.
 */
#[OA\Tag(name: 'Procurement Landed Costs', description: 'Freight, duty and other costs allocated to receipts and capitalized into stock')]
final class LandedCostController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(private readonly LandedCostService $landedCosts) {}

    /**
     * Paginated landed costs.
     */
    #[OA\Get(
        path: '/api/v1/procurement/landed-costs',
        operationId: 'procurementLandedCostsIndex',
        description: 'Landed costs of the company, newest first, optionally by status.',
        summary: 'List landed costs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Landed Costs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcStatusFilter'), new OA\Parameter(ref: '#/components/parameters/ProcPage'), new OA\Parameter(ref: '#/components/parameters/ProcPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Landed costs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementLandedCostResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LandedCost::query()->orderByDesc('cost_date');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return LandedCostResource::collection($query->gridPaginate($this->perPage($request)));
    }

    /**
     * Creates and allocates a landed cost.
     */
    #[OA\Post(
        path: '/api/v1/procurement/landed-costs',
        operationId: 'procurementLandedCostsStore',
        description: 'Creates a draft landed cost (amount in the accounting currency) and allocates it to the lines of the receipts by value (received quantity at cost), weight (unit weight of the order line) or quantity; the rounding remainder goes to the largest share.',
        summary: 'Create landed cost',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['cost_type', 'allocation_method', 'amount', 'cost_date', 'purchase_receipt_ids'], properties: [
            new OA\Property(property: 'cost_type', type: 'string', enum: ['freight', 'duty', 'insurance', 'handling', 'other']),
            new OA\Property(property: 'allocation_method', type: 'string', enum: ['value', 'weight', 'quantity']),
            new OA\Property(property: 'amount', type: 'string', example: '150.00'),
            new OA\Property(property: 'cost_date', type: 'string', format: 'date'),
            new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'purchase_receipt_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        ])),
        tags: ['Procurement Landed Costs'],
        responses: [
            new OA\Response(response: 201, description: 'Landed cost created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementLandedCostResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function store(StoreLandedCostRequest $request): JsonResponse
    {
        /** @var array{cost_type: string, allocation_method: string, amount: string, currency?: ?string, cost_date: string, description?: ?string, passive_invoice_id?: ?string, supplier_id?: ?string, purchase_receipt_ids: list<string>} $data */
        $data = $request->validated();

        return (new LandedCostResource($this->landedCosts->create($this->tenantId(), $data, $this->actorId($request))))->response()->setStatusCode(201);
    }

    /**
     * One landed cost with its allocation.
     */
    #[OA\Get(
        path: '/api/v1/procurement/landed-costs/{id}',
        operationId: 'procurementLandedCostsShow',
        description: 'A landed cost with its allocation to receipt lines.',
        summary: 'Show landed cost',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Landed Costs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Landed cost', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementLandedCostResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function show(string $id): LandedCostResource
    {
        return new LandedCostResource(LandedCost::query()->with('allocations')->findOrFail($id));
    }

    /**
     * Posts a draft landed cost.
     */
    #[OA\Post(
        path: '/api/v1/procurement/landed-costs/{id}/post',
        operationId: 'procurementLandedCostsPost',
        description: 'Adds each share to the moving average cost of the stock item through the inventory module and posts inventory against the landed cost clearing account through the general ledger.',
        summary: 'Post landed cost',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Landed Costs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Landed cost posted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementLandedCostResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function post(string $id): LandedCostResource
    {
        return new LandedCostResource($this->landedCosts->post(LandedCost::query()->findOrFail($id)));
    }

    /**
     * Cancels a draft landed cost.
     */
    #[OA\Post(
        path: '/api/v1/procurement/landed-costs/{id}/cancel',
        operationId: 'procurementLandedCostsCancel',
        description: 'Cancels a draft landed cost; a posted one cannot be cancelled.',
        summary: 'Cancel landed cost',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Landed Costs'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Landed cost cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementLandedCostResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function cancel(string $id): LandedCostResource
    {
        return new LandedCostResource($this->landedCosts->cancel(LandedCost::query()->findOrFail($id)));
    }
}
