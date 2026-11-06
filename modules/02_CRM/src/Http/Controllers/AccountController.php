<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\Http\Resources\AccountResource;
use Modules\CRM\Http\Resources\CustomerHealthProfileResource;
use Modules\CRM\Models\Account;
use OpenApi\Attributes as OA;

final class AccountController extends Controller
{
    public function __construct(
        private readonly CustomerHealthServiceInterface $healthService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of accounts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/accounts',
        operationId: 'listCrmAccounts',
        description: 'Returns the paginated list of accounts of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List accounts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'tier', in: 'query', required: false, description: 'Filter by tier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'churn_risk', in: 'query', required: false, description: 'Filter by churn risk', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of accounts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMAccountResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Account::where('tenant_id', $tenant->id);

        if ($request->has('tier')) {
            $query->where('tier', $request->query('tier'));
        }

        if ($request->has('churn_risk')) {
            $query->where('churn_risk', $request->query('churn_risk'));
        }

        $accounts = $query->orderBy('name')->paginate(25);

        return response()->json([
            'data' => AccountResource::collection($accounts),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    /**
     * Creates a new account in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/crm/accounts',
        operationId: 'createCrmAccount',
        description: 'Creates a new account in the active tenant.',
        summary: 'Create account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string', maxLength: 200), new OA\Property(property: 'tier', type: 'string', nullable: true, enum: ['strategic', 'enterprise', 'mid_market', 'smb', 'standard']), new OA\Property(property: 'industry', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'website', type: 'string', maxLength: 255, nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Account created', content: new OA\JsonContent(ref: '#/components/schemas/CRMAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'tier' => ['nullable', 'string', 'in:strategic,enterprise,mid_market,smb,standard'],
            'industry' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = $this->tenantContext->getTenant();
        $account = Account::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $tenant->id,
            'name' => $request->input('name'),
            'account_number' => 'ACC-'.strtoupper(Str::random(6)),
            'tier' => $request->input('tier', 'standard'),
            'industry' => $request->input('industry'),
            'website' => $request->input('website'),
            'owner_user_id' => $request->user()?->id,
            'customer_health_index' => 100.00,
            'churn_risk' => 'low',
        ]);

        return response()->json(new AccountResource($account), 201);
    }

    /**
     * Returns a single account of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/accounts/{account}',
        operationId: 'getCrmAccount',
        description: 'Returns a single account of the active tenant.',
        summary: 'Get account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new AccountResource($account));
    }

    /**
     * Updates an existing account of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/crm/accounts/{account}',
        operationId: 'updateCrmAccount',
        description: 'Updates an existing account of the active tenant. Also accepts the PATCH method.',
        summary: 'Update account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'name'), new OA\Property(property: 'tier'), new OA\Property(property: 'industry'), new OA\Property(property: 'website'), new OA\Property(property: 'status'), new OA\Property(property: 'annual_revenue'), new OA\Property(property: 'employee_count'), new OA\Property(property: 'tags')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);

        $account->update($request->only([
            'name', 'tier', 'industry', 'website', 'status', 'annual_revenue', 'employee_count', 'tags',
        ]));

        return response()->json(new AccountResource($account));
    }

    /**
     * Deletes a account of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/crm/accounts/{account}',
        operationId: 'deleteCrmAccount',
        description: 'Deletes a account of the active tenant.',
        summary: 'Delete account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Completed, no content returned'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);
        $account->delete();

        return response()->json(null, 204);
    }

    /**
     * Evaluates the health profile of a CRM account.
     */
    #[OA\Get(
        path: '/api/v1/crm/accounts/{id}/health',
        operationId: 'getHealthCrmAccount',
        description: 'Evaluates the health profile of a CRM account.',
        summary: 'Get account health',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMCustomerHealthProfileResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function health(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);

        $profile = $this->healthService->evaluateHealth($account);

        return response()->json(new CustomerHealthProfileResource($profile));
    }
}
