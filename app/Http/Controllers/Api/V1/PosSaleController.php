<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Retail\RefundPosSale;
use App\Actions\Retail\RegisterPosSale;
use App\Actions\Retail\SyncPosSaleToRt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retail\StorePosSaleRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Resources\RtFiscalReceiptResource;
use App\Http\Responses\ApiResponse;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\RtFiscalReceipt;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PosSaleController extends Controller
{
    use ApiResponse;

    #[OA\Post(
        path: '/api/v1/retail/pos/sales',
        operationId: 'registerPosSale',
        description: 'Register a retail checkout sale with multiple items and payment method tender breakdown.',
        summary: 'Register POS sale',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['pos_session_id', 'items'],
                properties: [
                    new OA\Property(property: 'pos_session_id', type: 'string', format: 'uuid'),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'description', type: 'string'),
                                new OA\Property(property: 'quantity', type: 'number'),
                                new OA\Property(property: 'unit_price', type: 'number'),
                                new OA\Property(property: 'tax_rate', type: 'number'),
                            ]
                        )
                    ),
                    new OA\Property(property: 'cash_tendered', type: 'number', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Sale registered successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StorePosSaleRequest $request, RegisterPosSale $registerSale): JsonResponse
    {
        $data = $request->validated();
        $sale = $registerSale->execute(PosSession::findOrFail($data['pos_session_id']), $data);

        $payload = (new CatalogResource($sale))->resolve($request);

        if (isset($data['cash_tendered'])) {
            $payload['change_due'] = round((float) $data['cash_tendered'] - (float) $sale->cash_amount, 2);
        }

        return $this->successResponse($payload, __('pos.messages.sale_registered'), Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/retail/pos/sales/{id}',
        operationId: 'getPosSale',
        description: 'Get retail sale transaction details including line items and fiscal receipt data.',
        summary: 'Get POS sale',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sale retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Sale not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(PosSale::with('items')->findOrFail($id)), __('pos.messages.sale_retrieved'));
    }

    #[OA\Post(
        path: '/api/v1/retail/pos/sales/{id}/refund',
        operationId: 'refundPosSale',
        description: 'Issue a full or partial refund for a previous POS retail sale transaction.',
        summary: 'Refund POS sale',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sale refunded successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Sale not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function refund(RefundPosSale $refundSale, string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource($refundSale->execute(PosSale::findOrFail($id))), __('pos.messages.sale_refunded'));
    }

    #[OA\Post(
        path: '/api/v1/retail/pos/sales/{id}/rt-transmit',
        operationId: 'transmitPosSaleToRt',
        description: 'Hands the sale to the fiscal printer (Registratore Telematico). The built-in printer is simulated: the receipt gets `rt_status` `simulated`, no `transmitted_at` and `reached_tax_authority` false, because nothing is sent to the Agenzia delle Entrate. `transmitted` is used only by a real RT service. When the printer fails the receipt is kept with `rt_status` `error` and calling the endpoint again retries it.',
        summary: 'Transmit sale to RT',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Retail & POS'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Fiscal receipt recorded (simulated or transmitted)', content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/ApiSuccessResponse'), new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RtFiscalReceiptResource')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Sale not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Already transmitted or refunded', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function rtTransmit(SyncPosSaleToRt $sync, string $id): JsonResponse
    {
        $receipt = $sync->execute(PosSale::findOrFail($id));
        $message = match ($receipt->rt_status) {
            RtFiscalReceipt::StatusSimulated => __('pos.messages.rt_simulated'),
            RtFiscalReceipt::StatusTransmitted => __('pos.messages.rt_transmitted'),
            default => __('pos.messages.rt_not_transmitted'),
        };

        return $this->successResponse(new RtFiscalReceiptResource($receipt), $message, Response::HTTP_CREATED);
    }
}
