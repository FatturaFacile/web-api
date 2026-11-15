<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use Illuminate\Http\JsonResponse;
use Modules\FiscalRetail\Http\Requests\CashMovementRequest;
use Modules\FiscalRetail\Http\Resources\RetailCashMovementResource;
use Modules\FiscalRetail\Models\RetailCashMovement;
use Modules\FiscalRetail\Services\CashDrawerService;
use OpenApi\Attributes as OA;

/**
 * Pay-ins, pay-outs and safe drops of the cash drawer of a POS session, with the cash that should be in the drawer.
 */
final class CashMovementController extends Controller
{
    #[OA\Get(path: '/api/v1/fiscal-retail/pos/sessions/{id}/cash-movements', operationId: 'listRetailCashMovements', description: 'Cash drawer movements of a session, oldest first, with the drawer summary (opening float, cash sales, pay-ins, pay-outs, safe drops, cash refunds and expected cash).', summary: 'List cash drawer movements', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Movements and summary', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailCashMovement')),
            new OA\Property(property: 'summary', ref: '#/components/schemas/RetailDrawerSummary'),
        ])),
        new OA\Response(response: 404, description: 'Session not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(string $id, CashDrawerService $drawer): JsonResponse
    {
        $session = PosSession::query()->findOrFail($id);
        $this->authorize('viewAny', RetailCashMovement::class);

        return response()->json([
            'data' => RetailCashMovementResource::collection(RetailCashMovement::query()->where('pos_session_id', $session->id)->orderBy('created_at')->get())->resolve(),
            'summary' => $drawer->summary($session),
        ]);
    }

    #[OA\Post(path: '/api/v1/fiscal-retail/pos/sessions/{id}/cash-movements', operationId: 'storeRetailCashMovement', description: 'Records a pay-in, a pay-out (reason required) or a safe drop on an open session. Pay-outs and safe drops cannot exceed the cash expected in the drawer. They change the expected cash used to compute the variance at closing.', summary: 'Record a cash drawer movement', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RetailCashMovementRequest')), tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Movement recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailCashMovement')])),
        new OA\Response(response: 404, description: 'Session not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error: closed session or not enough cash', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function store(CashMovementRequest $request, string $id, CashDrawerService $drawer): JsonResponse
    {
        $session = PosSession::query()->findOrFail($id);
        $this->authorize('create', RetailCashMovement::class);

        $movement = $drawer->record($session, $request->validated(), $request->user() !== null ? (string) $request->user()->getKey() : null);

        return (new RetailCashMovementResource($movement))->response()->setStatusCode(201);
    }
}
