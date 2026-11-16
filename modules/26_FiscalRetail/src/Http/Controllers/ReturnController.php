<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\FiscalRetail\Http\Requests\RetailReturnRequest;
use Modules\FiscalRetail\Http\Requests\RetailSaleRefundRequest;
use Modules\FiscalRetail\Http\Resources\RetailReturnResource;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Services\ReturnService;
use OpenApi\Attributes as OA;

/**
 * Returns and exchanges of POS sales, settled in an open cash session.
 */
final class ReturnController extends Controller
{
    #[OA\Get(path: '/api/v1/fiscal-retail/pos/returns', operationId: 'listRetailReturns', description: 'Paginated returns, newest first. Filters: pos_sale_id, pos_session_id.', summary: 'List POS returns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [
        new OA\Parameter(name: 'pos_sale_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'pos_session_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Returns', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailReturn'))])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RetailReturn::class);

        return RetailReturnResource::collection(
            RetailReturn::query()
                ->when($request->filled('pos_sale_id'), static fn ($query) => $query->where('pos_sale_id', (string) $request->query('pos_sale_id')))
                ->when($request->filled('pos_session_id'), static fn ($query) => $query->where('pos_session_id', (string) $request->query('pos_session_id')))
                ->orderByDesc('created_at')
                ->gridPaginate(max(1, min(100, $request->integer('per_page', 25))))
        );
    }

    #[OA\Get(path: '/api/v1/fiscal-retail/pos/returns/{id}', operationId: 'showRetailReturn', description: 'A return with its lines.', summary: 'Show a POS return', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Return', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailReturn')])),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $id): RetailReturnResource
    {
        $return = RetailReturn::query()->with('lines')->findOrFail($id);
        $this->authorize('view', $return);

        return new RetailReturnResource($return);
    }

    #[OA\Post(path: '/api/v1/fiscal-retail/pos/sales/{id}/returns', operationId: 'storeRetailReturn', description: 'Gives back part or all of the goods of a completed sale in an open session. Stock returns, the commercial document of the sale (if any) gets a return document, and cash given back lowers the expected cash of the drawer. With an exchange, the value of the returned goods pays the new ticket and only the rest is paid or refunded.', summary: 'Return goods of a sale', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RetailReturnRequest')), tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Return recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailReturn')])),
        new OA\Response(response: 404, description: 'Sale not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error: refunded sale, closed session, quantity above what is left to return', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function store(RetailReturnRequest $request, string $id, ReturnService $returns): JsonResponse
    {
        $sale = PosSale::query()->findOrFail($id);
        $this->authorize('create', RetailReturn::class);
        $validated = $request->validated();
        $session = PosSession::query()->findOrFail($validated['pos_session_id']);

        $return = $returns->process($sale, $session, $validated, $request->user() !== null ? (string) $request->user()->getKey() : null);

        return (new RetailReturnResource($return))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/fiscal-retail/pos/sales/{id}/refund', operationId: 'refundRetailSale', description: 'Gives back everything of the sale that earlier returns left, in an open session: one return with the remaining quantity of each line (stock, return document and drawer follow the same rules as a partial return). 422 when nothing is left to return.', summary: 'Refund a whole POS sale', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RetailSaleRefundRequest')), tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Return recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailReturn')])),
        new OA\Response(response: 404, description: 'Sale not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Nothing left to return, refunded sale or closed session', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function refundSale(RetailSaleRefundRequest $request, string $id, ReturnService $returns): JsonResponse
    {
        $sale = PosSale::query()->findOrFail($id);
        $this->authorize('create', RetailReturn::class);
        $validated = $request->validated();
        $session = PosSession::query()->findOrFail($validated['pos_session_id']);

        $return = $returns->refundWhole($sale, $session, $validated, $request->user() !== null ? (string) $request->user()->getKey() : null);

        return (new RetailReturnResource($return))->response()->setStatusCode(201);
    }
}
