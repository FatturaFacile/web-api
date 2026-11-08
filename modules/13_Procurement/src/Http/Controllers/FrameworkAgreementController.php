<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Http\Controllers\Concerns\ProcurementControllerHelpers;
use Modules\Procurement\Http\Requests\StoreCallOffRequest;
use Modules\Procurement\Http\Requests\StoreContractLineRequest;
use Modules\Procurement\Http\Resources\PurchaseOrderResource;
use Modules\Procurement\Http\Resources\SupplierContractLineResource;
use Modules\Procurement\Models\SupplierContract;
use Modules\Procurement\Services\FrameworkAgreementService;
use OpenApi\Attributes as OA;

/**
 * Framework (blanket) agreements: agreed prices with validity and committed quantities, and call-off orders.
 */
#[OA\Tag(name: 'Procurement Framework Agreements', description: 'Agreement lines with price validity and call-off orders')]
final class FrameworkAgreementController extends Controller
{
    use ProcurementControllerHelpers;

    public function __construct(private readonly FrameworkAgreementService $agreements) {}

    /**
     * Lines of an agreement.
     */
    #[OA\Get(
        path: '/api/v1/procurement/contracts/{id}/lines',
        operationId: 'procurementContractLinesIndex',
        description: 'Items of the agreement with agreed price, validity, committed, released and remaining quantity.',
        summary: 'List agreement lines',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Procurement Framework Agreements'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Lines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementContractLineResource'))])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
        ]
    )]
    public function lines(string $id): AnonymousResourceCollection
    {
        return SupplierContractLineResource::collection(SupplierContract::query()->findOrFail($id)->lines()->orderBy('price_valid_from')->get());
    }

    /**
     * Adds an item to the agreement.
     */
    #[OA\Post(
        path: '/api/v1/procurement/contracts/{id}/lines',
        operationId: 'procurementContractLinesStore',
        description: 'Adds an agreed price with its validity (within the agreement period) and an optional committed quantity.',
        summary: 'Add agreement line',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ProcurementContractLineResource')),
        tags: ['Procurement Framework Agreements'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Line added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ProcurementContractLineResource')])),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function storeLine(StoreContractLineRequest $request, string $id): JsonResponse
    {
        /** @var array{description: string, unit_price: string, price_valid_from: string, price_valid_to?: ?string, supplier_catalog_id?: ?string, uom?: ?string, tax_rate?: string|null, committed_quantity?: string|null} $data */
        $data = $request->validated();

        return (new SupplierContractLineResource($this->agreements->addLine(SupplierContract::query()->findOrFail($id), $data)))->response()->setStatusCode(201);
    }

    /**
     * Creates a call-off order on the agreement.
     */
    #[OA\Post(
        path: '/api/v1/procurement/contracts/{id}/call-offs',
        operationId: 'procurementContractCallOffsStore',
        description: 'Creates a draft purchase order of type blanket_release on an active agreement: each line takes the agreed price valid on the order date and cannot exceed the remaining quantity of the item or the remaining amount of the agreement.',
        summary: 'Create call-off order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['expected_delivery_date', 'lines'], properties: [
            new OA\Property(property: 'order_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'expected_delivery_date', type: 'string', format: 'date'),
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'supplier_contract_line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity_ordered', type: 'string'),
            ])),
        ])),
        tags: ['Procurement Framework Agreements'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ProcRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Call-off created', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementPurchaseOrder')),
            new OA\Response(ref: '#/components/responses/ProcUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ProcForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ProcNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ProcUnprocessable', response: 422),
        ]
    )]
    public function callOff(StoreCallOffRequest $request, string $id): JsonResponse
    {
        /** @var array{order_date?: ?string, expected_delivery_date: string, cost_center_id?: ?string, project_id?: ?string, notes?: ?string, lines: list<array{supplier_contract_line_id: string, quantity_ordered: string, expected_delivery_date?: ?string}>} $data */
        $data = $request->validated();
        $order = $this->agreements->callOff(SupplierContract::query()->findOrFail($id), $data, $this->actorId($request));

        return response()->json(new PurchaseOrderResource($order->load('lines')), 201);
    }
}
