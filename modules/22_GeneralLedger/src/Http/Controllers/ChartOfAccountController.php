<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\CreateAccountCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\CreateAccountHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\DeleteAccountCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\DeleteAccountHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\MoveAccountCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\MoveAccountHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\UpdateAccountCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\UpdateAccountHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\ExportChartOfAccountsHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\ExportChartOfAccountsQuery;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\GetAccountDetailHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\GetAccountDetailQuery;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\GetAccountTreeHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\GetAccountTreeQuery;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\ListAccountsHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\ListAccountsQuery;
use Modules\GeneralLedger\Http\Requests\MoveChartOfAccountRequest;
use Modules\GeneralLedger\Http\Requests\StoreChartOfAccountRequest;
use Modules\GeneralLedger\Http\Resources\ChartOfAccountResource;
use Modules\GeneralLedger\Models\ChartOfAccount;
use OpenApi\Attributes as OA;

final class ChartOfAccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListAccountsHandler $listAccounts,
        private readonly GetAccountTreeHandler $getAccountTree,
        private readonly GetAccountDetailHandler $getAccountDetail,
        private readonly CreateAccountHandler $createAccount,
        private readonly UpdateAccountHandler $updateAccount,
        private readonly MoveAccountHandler $moveAccount,
        private readonly DeleteAccountHandler $deleteAccount,
        private readonly ExportChartOfAccountsHandler $exportAccounts,
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
            new OA\Parameter(name: 'postable', in: 'query', required: false, description: 'Filter by postable flag', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'template_code', in: 'query', required: false, description: 'Filter by standard/template code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Case-insensitive match on account code, name or statutory code', schema: new OA\Schema(type: 'string')),
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
        $tenantId = $this->resolveTenantId($request);

        $accounts = $this->listAccounts->handle(new ListAccountsQuery(
            tenantId: $tenantId,
            accountType: $request->query('type') !== null ? (string) $request->query('type') : null,
            isActive: $request->query('active') !== null ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            isPostable: $request->query('postable') !== null ? filter_var($request->query('postable'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            templateCode: $request->query('template_code') !== null ? (string) $request->query('template_code') : null,
            search: $request->query('search') !== null ? (string) $request->query('search') : null,
            perPage: (int) $request->query('per_page', 50),
        ));

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
        $tenantId = $this->resolveTenantId($request);

        $validated = $request->validated();
        $displayName = (string) ($validated['account_name'] ?? $validated['name'] ?? $validated['account_code']);
        $validated['name'] = $displayName;
        unset($validated['account_name']);

        $account = $this->createAccount->handle(new CreateAccountCommand(
            tenantId: $tenantId,
            attributes: $validated,
        ));

        return response()->json([
            'message' => 'Account created successfully',
            'data' => new ChartOfAccountResource($account),
        ], 201);
    }

    /**
     * Returns a single account of the active tenant with breadcrumbs and mappings.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accounts/{account}',
        operationId: 'getGeneralLedgerAccount',
        description: 'Returns a single account of the active tenant with hierarchy breadcrumbs and cross-standard mappings.',
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
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $detail = $this->getAccountDetail->handle(new GetAccountDetailQuery($tenantId, $id));
        $this->authorize('view', $detail['account']);

        return response()->json([
            'data' => new ChartOfAccountResource($detail['account']),
            'meta' => [
                'breadcrumbs' => $detail['breadcrumbs'],
                'has_postings' => $detail['has_postings'],
            ],
        ]);
    }

    /**
     * Updates an existing account of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/general-ledger/accounts/{account}',
        operationId: 'updateGeneralLedgerAccount',
        description: 'Updates an existing account of the active tenant. Also accepts the PATCH method. The type, code and normal balance of an account that already carries postings cannot change (409); an account cannot be placed under itself or one of its sub-accounts (422); the code must stay unique in the tenant (422).',
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

        $validated = $request->validated();
        $displayName = (string) ($validated['account_name'] ?? $validated['name'] ?? $account->name);
        $validated['name'] = $displayName;
        unset($validated['account_name']);

        $updated = $this->updateAccount->handle(new UpdateAccountCommand($account, $validated));

        return response()->json([
            'message' => 'Account updated successfully',
            'data' => new ChartOfAccountResource($updated),
        ]);
    }

    /**
     * Moves an account under a new parent (or promotes to root when parent_account_id is null).
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/accounts/{account}/move',
        operationId: 'moveGeneralLedgerAccount',
        description: 'Re-parents an account in the hierarchy after validating against cycles and recalculating subtree depth and paths.',
        summary: 'Move account in hierarchy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'parent_account_id', type: 'string', format: 'uuid', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Account moved', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerChartOfAccountResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Cycle detected', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function move(MoveChartOfAccountRequest $request, string $id): JsonResponse
    {
        $account = ChartOfAccount::findOrFail($id);
        $this->authorize('update', $account);

        $parentId = $request->validated('parent_account_id');
        $moved = $this->moveAccount->handle(new MoveAccountCommand(
            account: $account,
            parentAccountId: is_string($parentId) && $parentId !== '' ? $parentId : null,
        ));

        return response()->json([
            'message' => 'Account moved successfully',
            'data' => new ChartOfAccountResource($moved),
        ]);
    }

    /**
     * Deletes an account of the active tenant.
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

        $this->deleteAccount->handle(new DeleteAccountCommand($account));

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }

    /**
     * Returns the unlimited-depth Chart of Accounts tree with backend-seeded UI copy.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accounts-tree',
        operationId: 'getHierarchyGeneralLedgerAccountsTree',
        description: 'Returns the unlimited-depth hierarchical Chart of Accounts tree of the active tenant ordered by account code.',
        summary: 'Get chart of accounts tree',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'template_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function hierarchy(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        return response()->json($this->getAccountTree->handle(new GetAccountTreeQuery(
            tenantId: $tenantId,
            templateCode: $request->query('template_code') !== null ? (string) $request->query('template_code') : null,
            accountType: $request->query('type') !== null ? (string) $request->query('type') : null,
            isActive: $request->query('active') !== null ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            search: $request->query('search') !== null ? (string) $request->query('search') : null,
            locale: app()->getLocale(),
        )));
    }

    /**
     * Audit-ready export of the Chart of Accounts and multi-standard mappings in XLSX, CSV, or XML.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/accounts/export',
        operationId: 'exportGeneralLedgerAccounts',
        description: 'Exports the Chart of Accounts and multi-standard mappings in audit-ready Excel (.xlsx), CSV (.csv), or XML (.xml) format.',
        summary: 'Export chart of accounts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['xlsx', 'csv', 'xml'], default: 'xlsx')),
            new OA\Parameter(name: 'template_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Export file download'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function export(Request $request): Response
    {
        $this->authorize('viewAny', ChartOfAccount::class);
        $tenantId = $this->resolveTenantId($request);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'csv', 'xml'], true)) {
            $format = 'xlsx';
        }

        $result = $this->exportAccounts->handle(new ExportChartOfAccountsQuery(
            tenantId: $tenantId,
            format: $format,
            templateCode: $request->query('template_code') !== null ? (string) $request->query('template_code') : null,
            isActive: $request->query('active') !== null ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
        ));

        return response($result['content'], 200, [
            'Content-Type' => $result['mime'],
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    private function resolveTenantId(Request $request): string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }
}
