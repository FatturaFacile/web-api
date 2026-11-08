<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Treasury\Http\Requests\CloseCashRegisterRequest;
use Modules\Treasury\Http\Requests\StoreCashMovementRequest;
use Modules\Treasury\Http\Requests\StoreCashRegisterRequest;
use Modules\Treasury\Http\Resources\CashClosingResource;
use Modules\Treasury\Http\Resources\CashMovementResource;
use Modules\Treasury\Http\Resources\CashRegisterResource;
use Modules\Treasury\Models\CashClosing;
use Modules\Treasury\Models\CashMovement;
use Modules\Treasury\Models\CashRegister;
use Modules\Treasury\Services\CashRegisterService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cash registers (prima nota cassa): receipts and payments in cash or by cheque, posted to the ledger, and the daily
 * closing against the counted cash.
 */
final class CashRegisterController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashRegisterService $cash) {}

    /**
     * The cash registers of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/cash-registers',
        operationId: 'listTreasuryCashRegisters',
        description: 'Lists the cash registers of the active tenant with their balance and last closed day.',
        summary: 'List cash registers',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash'],
        responses: [
            new OA\Response(response: 200, description: 'Cash registers', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryCashRegisterResource')),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(CashRegisterResource::collection(CashRegister::query()->orderBy('code')->get()), 'Cash registers');
    }

    /**
     * Opens a cash register.
     */
    #[OA\Post(
        path: '/api/v1/treasury/cash-registers',
        operationId: 'createTreasuryCashRegister',
        description: 'Opens a cash register on a ledger account of the chart of accounts, with its opening balance.',
        summary: 'Create cash register',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreCashRegisterRequest')),
        tags: ['Treasury Cash'],
        responses: [
            new OA\Response(response: 201, description: 'Cash register created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryCashRegisterResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function store(StoreCashRegisterRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $register = $this->cash->create((string) $tenantContext->getTenantId(), $request->validated());

        return $this->successResponse(new CashRegisterResource($register->refresh()), 'Cash register created', Response::HTTP_CREATED);
    }

    /**
     * One cash register.
     */
    #[OA\Get(
        path: '/api/v1/treasury/cash-registers/{id}',
        operationId: 'getTreasuryCashRegister',
        description: 'Returns a cash register of the active tenant.',
        summary: 'Get cash register',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryCashRegisterId')],
        responses: [
            new OA\Response(response: 200, description: 'Cash register', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryCashRegisterResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CashRegisterResource(CashRegister::query()->findOrFail($id)), 'Cash register');
    }

    /**
     * The movements of a cash register, newest first.
     */
    #[OA\Get(
        path: '/api/v1/treasury/cash-registers/{id}/movements',
        operationId: 'listTreasuryCashMovements',
        description: 'Lists the receipts and payments of a cash register, newest first (the cash book).',
        summary: 'List cash movements',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryCashRegisterId')],
        responses: [
            new OA\Response(response: 200, description: 'Cash movements', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryCashMovementResource')),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
        ]
    )]
    public function movements(string $id): JsonResponse
    {
        $register = CashRegister::query()->findOrFail($id);
        $movements = CashMovement::query()->where('cash_register_id', $register->id)->orderByDesc('movement_date')->orderByDesc('created_at')->limit(500)->get();

        return $this->successResponse(CashMovementResource::collection($movements), 'Cash movements');
    }

    /**
     * Records a receipt or a payment.
     */
    #[OA\Post(
        path: '/api/v1/treasury/cash-registers/{id}/movements',
        operationId: 'createTreasuryCashMovement',
        description: 'Records a receipt (Dr cash / Cr counter account) or payment (Dr counter account / Cr cash), in cash or by cheque, and posts it. Refused on or before the last closed day, when the cash would go below zero, or in a closed fiscal period. A retry with the same idempotency_key returns the movement already recorded.',
        summary: 'Record cash movement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreCashMovementRequest')),
        tags: ['Treasury Cash'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryCashRegisterId')],
        responses: [
            new OA\Response(response: 201, description: 'Movement posted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryCashMovementResource'),
            ])),
            new OA\Response(response: 200, description: 'Movement already recorded with this idempotency key', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function storeMovement(StoreCashMovementRequest $request, TenantContext $tenantContext, string $id): JsonResponse
    {
        $register = CashRegister::query()->findOrFail($id);
        $movement = $this->cash->recordMovement((string) $tenantContext->getTenantId(), (string) $register->id, $request->validated(), (string) $request->user()?->getAuthIdentifier());

        return $this->successResponse(new CashMovementResource($movement), 'Cash movement posted', $movement->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Closes a day of the register.
     */
    #[OA\Post(
        path: '/api/v1/treasury/cash-registers/{id}/close',
        operationId: 'closeTreasuryCashRegister',
        description: 'Daily closing: compares the balance given by the movements with the counted cash, posts any difference on banking.ledger_defaults.cash_difference_account_code (refused with 422 when not configured) and freezes the day and the days before it.',
        summary: 'Close cash register day',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryCloseCashRegisterRequest')),
        tags: ['Treasury Cash'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryCashRegisterId')],
        responses: [
            new OA\Response(response: 201, description: 'Day closed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryCashClosingResource'),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function close(CloseCashRegisterRequest $request, TenantContext $tenantContext, string $id): JsonResponse
    {
        $register = CashRegister::query()->findOrFail($id);
        $closing = $this->cash->close(
            (string) $tenantContext->getTenantId(),
            (string) $register->id,
            (string) $request->validated('closing_date'),
            (string) $request->validated('counted_balance'),
            $request->validated('notes'),
            (string) $request->user()?->getAuthIdentifier(),
        );

        return $this->successResponse(new CashClosingResource($closing->refresh()), 'Cash register closed', Response::HTTP_CREATED);
    }

    /**
     * The daily closings of a register, newest first.
     */
    #[OA\Get(
        path: '/api/v1/treasury/cash-registers/{id}/closings',
        operationId: 'listTreasuryCashClosings',
        description: 'Lists the daily closings of a cash register, newest first.',
        summary: 'List cash closings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Cash'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryCashRegisterId')],
        responses: [
            new OA\Response(response: 200, description: 'Closings', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryCashClosingResource')),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
        ]
    )]
    public function closings(string $id): JsonResponse
    {
        $register = CashRegister::query()->findOrFail($id);

        return $this->successResponse(CashClosingResource::collection(CashClosing::query()->where('cash_register_id', $register->id)->orderByDesc('closing_date')->limit(400)->get()), 'Cash closings');
    }
}
