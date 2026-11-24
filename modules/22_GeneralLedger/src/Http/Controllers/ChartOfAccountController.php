<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Http\Requests\StoreChartOfAccountRequest;
use Modules\GeneralLedger\Http\Resources\ChartOfAccountResource;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartOfAccountService;
use OpenApi\Attributes as OA;

final class ChartOfAccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ChartOfAccountService $accounts,
    ) {}

    /**
     * Returns the paginated list of accounts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accounts',
        operationId: 'listGeneralLedgerAccounts',
        description: 'Returns the paginated list of accounts of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List accounts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Filter by type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'active', in: 'query', required: false, description: 'Filter by active', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Case-insensitive match on account code or name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of accounts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerChartOfAccountResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $accounts = ChartOfAccount::where('tenant_id', $tenantId)
            ->when($request->query('type'), fn ($q, $type) => $q->where('account_type', $type))
            ->when($request->query('active'), fn ($q, $act) => $q->where('is_active', filter_var($act, FILTER_VALIDATE_BOOLEAN)))
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($inner) => $inner->whereLike('account_code', "%{$term}%")->orWhereLike('name', "%{$term}%")))
            ->orderBy('account_code')
            ->gridPaginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => ChartOfAccountResource::collection($accounts),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    /**
     * Creates a new account in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/accounts',
        operationId: 'createGeneralLedgerAccount',
        description: 'Creates a new account in the active tenant.',
        summary: 'Create account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreChartOfAccountRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Account created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerChartOfAccountResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $this->authorize('create', ChartOfAccount::class);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $account = ChartOfAccount::create(array_merge($request->validated(), [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
        ]));

        return response()->json([
            'message' => 'Account created successfully',
            'data' => new ChartOfAccountResource($account),
        ], 201);
    }

    /**
     * Returns a single account of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accounts/{account}',
        operationId: 'getGeneralLedgerAccount',
        description: 'Returns a single account of the active tenant.',
        summary: 'Get account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerChartOfAccountResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $account = ChartOfAccount::with('subAccounts')->findOrFail($id);
        $this->authorize('view', $account);

        return response()->json([
            'data' => new ChartOfAccountResource($account),
        ]);
    }

    /**
     * Updates an existing account of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/general-ledger/accounts/{account}',
        operationId: 'updateGeneralLedgerAccount',
        description: 'Updates an existing account of the active tenant. Also accepts the PATCH method. The type and normal balance of an account that already carries postings cannot change (409); an account cannot be placed under itself or one of its sub-accounts (422); the code must stay unique in the tenant (422).',
        summary: 'Update account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreChartOfAccountRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerChartOfAccountResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Account carries postings, has sub-accounts or is a default posting account', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(StoreChartOfAccountRequest $request, string $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $this->authorize('update', $account);

        $account = $this->accounts->update($account, $request->validated());

        return response()->json([
            'message' => 'Account updated successfully',
            'data' => new ChartOfAccountResource($account),
        ]);
    }

    /**
     * Deletes a account of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/general-ledger/accounts/{account}',
        operationId: 'deleteGeneralLedgerAccount',
        description: 'Deletes an account of the active tenant. Refused with 409 when the account carries postings (deactivate it instead), has sub-accounts or is a default posting account.',
        summary: 'Delete account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Account carries postings, has sub-accounts or is a default posting account', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $this->authorize('delete', $account);

        $this->accounts->delete($account);

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }

    /**
     * Returns the root accounts of the active tenant with their sub-accounts, ordered by account code.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accounts-tree',
        operationId: 'getHierarchyGeneralLedgerAccountsTree',
        description: 'Returns the root accounts of the active tenant with their sub-accounts, ordered by account code.',
        summary: 'Get chart of accounts tree',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerChartOfAccountResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function hierarchy(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $rootAccounts = ChartOfAccount::where('tenant_id', $tenantId)
            ->whereNull('parent_account_id')
            ->with('subAccounts')
            ->orderBy('account_code')
            ->get();

        return response()->json([
            'data' => ChartOfAccountResource::collection($rootAccounts),
        ]);
    }
}
