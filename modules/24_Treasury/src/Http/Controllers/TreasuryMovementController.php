<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Treasury\Http\Requests\StoreBankChargeRequest;
use Modules\Treasury\Http\Requests\StoreTransferRequest;
use Modules\Treasury\Http\Resources\TreasuryBankMovementResource;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\TreasuryBankMovement;
use Modules\Treasury\Services\TreasuryBankMovementService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal transfers between bank accounts, bank fees and interest: each one is posted to the general ledger and moves
 * the balances of the bank accounts in the same transaction.
 */
final class TreasuryMovementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TreasuryBankMovementService $movements) {}

    /**
     * Lists the treasury bank movements, newest first.
     */
    #[OA\Get(
        path: '/api/v1/treasury/movements',
        operationId: 'listTreasuryBankMovements',
        description: 'Lists the treasury bank movements (transfers, bank fees, interest) of the active tenant, newest first.',
        summary: 'List treasury bank movements',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Movements'],
        parameters: [
            new OA\Parameter(name: 'movement_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['transfer', 'bank_fee', 'interest_income', 'interest_expense'])),
            new OA\Parameter(name: 'bank_account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/TreasuryPerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Movements', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryBankMovementResource')),
                new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer')]),
            ])),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'movement_type' => ['nullable', Rule::in([TreasuryBankMovement::TypeTransfer, ...TreasuryBankMovement::ChargeTypes])],
            'bank_account_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = TreasuryBankMovement::query()
            ->when($filters['movement_type'] ?? null, fn ($query, string $type) => $query->where('movement_type', $type))
            ->when($filters['bank_account_id'] ?? null, fn ($query, string $id) => $query->where(fn ($inner) => $inner->where('bank_account_id', $id)->orWhere('counter_bank_account_id', $id)))
            ->orderByDesc('movement_date')
            ->orderByDesc('created_at')
            ->gridPaginate((int) ($filters['per_page'] ?? 25));

        return $this->successResponse(TreasuryBankMovementResource::collection($page->items()), 'Treasury bank movements', meta: ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    /**
     * Transfers money between two bank accounts of the tenant.
     */
    #[OA\Post(
        path: '/api/v1/treasury/transfers',
        operationId: 'createTreasuryTransfer',
        description: 'Moves money between two active bank accounts of the tenant in the same currency. Posted Dr ledger account of the receiving bank / Cr ledger account of the paying bank; both bank accounts must be linked to the chart of accounts. A retry with the same idempotency_key returns the transfer already recorded (200).',
        summary: 'Internal transfer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreTransferRequest')),
        tags: ['Treasury Movements'],
        responses: [
            new OA\Response(response: 201, description: 'Transfer posted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryBankMovementResource'),
            ])),
            new OA\Response(response: 200, description: 'Transfer already recorded with this idempotency key (same body as the 201)', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankMovementEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function transfer(StoreTransferRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $movement = $this->movements->transfer((string) $tenantContext->getTenantId(), $request->validated(), (string) $request->user()?->getAuthIdentifier());

        return $this->successResponse(new TreasuryBankMovementResource($movement), 'Transfer posted', $movement->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Posts a bank fee or interest on a bank account.
     */
    #[OA\Post(
        path: '/api/v1/treasury/bank-accounts/{id}/charges',
        operationId: 'createTreasuryBankCharge',
        description: 'Posts a bank fee (Dr bank_charges_account_code / Cr bank), interest income (Dr bank / Cr interest_income_account_code) or interest expense (Dr interest_expense_account_code / Cr bank) on a bank account linked to the chart of accounts. The counter accounts come from banking.ledger_defaults: without one the posting is refused with 422. A retry with the same idempotency_key returns the movement already recorded.',
        summary: 'Bank fee or interest',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreBankChargeRequest')),
        tags: ['Treasury Movements'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TreasuryBankAccountId')],
        responses: [
            new OA\Response(response: 201, description: 'Movement posted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryBankMovementResource'),
            ])),
            new OA\Response(response: 200, description: 'Movement already recorded with this idempotency key (same body as the 201)', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankMovementEnvelope')),
            new OA\Response(ref: '#/components/responses/TreasuryUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/TreasuryForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/TreasuryNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TreasuryValidationError', response: 422),
        ]
    )]
    public function charge(StoreBankChargeRequest $request, TenantContext $tenantContext, string $id): JsonResponse
    {
        $account = BankAccount::query()->findOrFail($id);
        $movement = $this->movements->charge((string) $tenantContext->getTenantId(), (string) $account->id, $request->validated(), (string) $request->user()?->getAuthIdentifier());

        return $this->successResponse(new TreasuryBankMovementResource($movement), 'Bank movement posted', $movement->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
