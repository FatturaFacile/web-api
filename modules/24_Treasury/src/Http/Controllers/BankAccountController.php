<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Treasury\Http\Requests\StoreBankAccountRequest;
use Modules\Treasury\Http\Resources\BankAccountResource;
use Modules\Treasury\Models\BankAccount;
use OpenApi\Attributes as OA;

final class BankAccountController extends Controller
{
    /**
     * Lists the bank accounts of the active tenant, paginated.
     */
    #[OA\Get(
        path: '/api/v1/treasury/bank-accounts',
        operationId: 'listTreasuryBankAccounts',
        description: 'Lists the bank accounts of the active tenant, paginated, optionally by currency and active flag.',
        summary: 'List bank accounts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Bank Accounts'],
        parameters: [
            new OA\Parameter(name: 'currency', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'CHF')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Bank accounts', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryBankAccountResource')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/TreasuryPaginatorMeta'),
                new OA\Property(property: 'links', ref: '#/components/schemas/TreasuryPaginatorLinks'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = BankAccount::where('tenant_id', $tenantId);

        if ($request->has('currency')) {
            $query->where('currency', strtoupper($request->query('currency')));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $accounts = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(BankAccountResource::collection($accounts)->response()->getData(true));
    }

    /**
     * Creates a new bank account in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/treasury/bank-accounts',
        operationId: 'createTreasuryBankAccount',
        description: 'Creates a new bank account in the active tenant.',
        summary: 'Create bank account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Bank Accounts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreBankAccountRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Bank account created', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreBankAccountRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validated();
        $tenantId = $tenantContext->getTenantId();

        $account = BankAccount::create(array_merge($data, [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'current_balance' => $data['opening_balance'] ?? 0.00,
            'available_balance' => $data['opening_balance'] ?? 0.00,
            'is_active' => true,
        ]));

        return response()->json(new BankAccountResource($account), 201);
    }

    /**
     * Returns a single bank account of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/bank-accounts/{bank_account}',
        operationId: 'getTreasuryBankAccount',
        description: 'Returns a single bank account of the active tenant.',
        summary: 'Get bank account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Bank Accounts'],
        parameters: [
            new OA\Parameter(name: 'bank_account', in: 'path', required: true, description: 'Identifier of the bank account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $account = BankAccount::findOrFail($id);

        return response()->json(new BankAccountResource($account));
    }

    /**
     * Updates an existing bank account of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/treasury/bank-accounts/{bank_account}',
        operationId: 'updateTreasuryBankAccount',
        description: 'Updates an existing bank account of the active tenant. Also accepts the PATCH method.',
        summary: 'Update bank account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Bank Accounts'],
        parameters: [
            new OA\Parameter(name: 'bank_account', in: 'path', required: true, description: 'Identifier of the bank account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'bank_name', type: 'string'), new OA\Property(property: 'branch_name', type: 'string'), new OA\Property(property: 'bic_swift', type: 'string'), new OA\Property(property: 'gl_account_id', type: 'string', format: 'uuid'), new OA\Property(property: 'overdraft_limit', type: 'number'), new OA\Property(property: 'credit_line_limit', type: 'number'), new OA\Property(property: 'is_default', type: 'boolean'), new OA\Property(property: 'is_active', type: 'boolean')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryBankAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(StoreBankAccountRequest $request, string $id): JsonResponse
    {
        $account = BankAccount::findOrFail($id);
        $account->update($request->validated());

        return response()->json(new BankAccountResource($account));
    }

    /**
     * Deletes a bank account of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/treasury/bank-accounts/{bank_account}',
        operationId: 'deleteTreasuryBankAccount',
        description: 'Deletes a bank account of the active tenant.',
        summary: 'Delete bank account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Bank Accounts'],
        parameters: [
            new OA\Parameter(name: 'bank_account', in: 'path', required: true, description: 'Identifier of the bank account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $account = BankAccount::findOrFail($id);
        $account->delete();

        return response()->json(['message' => 'Bank account soft deleted successfully.']);
    }
}
