<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Http\JsonResponse;
use Modules\FiscalRetail\Http\Requests\TicketRequest;
use Modules\FiscalRetail\Http\Resources\TicketResource;
use Modules\FiscalRetail\Services\ReturnService;
use Modules\FiscalRetail\Services\TicketService;
use OpenApi\Attributes as OA;

/**
 * Sales tickets of the till: promotions applied by the server and the total paid with several methods.
 */
final class TicketController extends Controller
{
    #[OA\Post(path: '/api/v1/fiscal-retail/pos/tickets', operationId: 'storeRetailTicket', description: 'Registers a POS sale from lines, applying the active promotions and splitting the payment across methods. Cash may exceed what is due (change); every other method must not. A ticket whose promotions cover it entirely needs no payment.', summary: 'Sell a ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RetailTicketRequest')), tags: ['Fiscal Retail'], responses: [
        new OA\Response(response: 201, description: 'Ticket registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailTicket')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error: closed session, payments that do not cover the total', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function store(TicketRequest $request, TicketService $tickets): JsonResponse
    {
        $this->authorize('create', PosSale::class);
        $validated = $request->validated();
        $session = PosSession::query()->findOrFail($validated['pos_session_id']);

        $sale = $tickets->create($session, $validated);

        return (new TicketResource($sale->load('items')))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/fiscal-retail/pos/tickets/{id}', operationId: 'showRetailTicket', description: 'A ticket with its lines, payments by method and promotions applied, and for every line the quantity still returnable.', summary: 'Show a ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Ticket', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailTicket')])),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $id, ReturnService $returns): JsonResponse
    {
        $sale = PosSale::query()->with('items')->findOrFail($id);
        $this->authorize('view', $sale);

        $returned = $returns->returnedQuantities($sale);
        $payload = (new TicketResource($sale))->resolve();
        $payload['items'] = array_map(static fn (array $item): array => $item + [
            'returned_quantity' => $returned[$item['id']] ?? '0.0000',
            'returnable_quantity' => bccomp($item['unit_price'], '0', 4) > 0 ? bcsub($item['quantity'], $returned[$item['id']] ?? '0', 4) : '0.0000',
        ], $payload['items']);

        return response()->json(['data' => $payload]);
    }
}
