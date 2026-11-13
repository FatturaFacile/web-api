<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\CustomerHealthServiceInterface;
use Modules\CRM\Http\Requests\StoreAccountRequest;
use Modules\CRM\Http\Requests\UpdateAccountRequest;
use Modules\CRM\Http\Resources\AccountResource;
use Modules\CRM\Http\Resources\CustomerHealthProfileResource;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\PartyLinkLog;
use Modules\CRM\Services\PartyMaster\AccountPartyService;
use OpenApi\Attributes as OA;

/**
 * CRM accounts. Each account references a contact, the party master shared with invoicing, quotes and orders: the
 * account holds CRM attributes, the contact holds master data, and the responses expose both.
 */
final class AccountController extends Controller
{
    public function __construct(
        private readonly CustomerHealthServiceInterface $healthService,
        private readonly AccountPartyService $partyService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of accounts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/accounts',
        operationId: 'listCrmAccounts',
        description: 'Returns the paginated list of accounts of the active tenant, each with the master data of its contact. Results can be narrowed with the query parameters.',
        summary: 'List accounts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'tier', in: 'query', required: false, description: 'Filter by tier', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'churn_risk', in: 'query', required: false, description: 'Filter by churn risk', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Accounts of a contact', schema: new OA\Schema(type: 'string', format: 'uuid')),
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
        $query = Account::with('primaryContact')->where('tenant_id', $tenant->id);

        if ($request->has('tier')) {
            $query->where('tier', $request->query('tier'));
        }

        if ($request->has('churn_risk')) {
            $query->where('churn_risk', $request->query('churn_risk'));
        }

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->query('contact_id'));
        }

        $accounts = $query->orderBy('name')->gridPaginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => AccountResource::collection($accounts),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'last_page' => $accounts->lastPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
            ],
        ]);
    }

    /**
     * Creates a new account linked to its contact: the given contact, the one carrying the same VAT number, fiscal
     * code or email, or a new one built from the master data.
     */
    #[OA\Post(
        path: '/api/v1/crm/accounts',
        operationId: 'createCrmAccount',
        description: 'Creates a new account in the active tenant. The account references a contact (the party master shared with invoices, quotes and orders): the given contact_id, else the single contact with the same VAT number, fiscal code or email, else a new customer contact built from the master data fields.',
        summary: 'Create account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMStoreAccountRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Account created', content: new OA\JsonContent(ref: '#/components/schemas/CRMAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->partyService->createAccount(
            (string) $this->tenantContext->getTenant()->id,
            $request->validated(),
            $request->user()?->id !== null ? (string) $request->user()->id : null,
        );

        return response()->json(new AccountResource($account->load('primaryContact')), 201);
    }

    /**
     * Returns a single account of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/accounts/{account}',
        operationId: 'getCrmAccount',
        description: 'Returns a single account of the active tenant with the master data of its contact.',
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
        $account = Account::with('primaryContact')->where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new AccountResource($account));
    }

    /**
     * Updates an account: CRM attributes on the account, master data on its contact.
     */
    #[OA\Put(
        path: '/api/v1/crm/accounts/{account}',
        operationId: 'updateCrmAccount',
        description: 'Updates an existing account of the active tenant. CRM attributes are stored on the account; master data fields (name, vat_number, fiscal_code, email, phone, pec, sdi_code, country) are written to the linked contact. contact_id relinks the account to another contact. Also accepts the PATCH method.',
        summary: 'Update account',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, description: 'Identifier of the account', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/CRMUpdateAccountRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMAccountResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateAccountRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $account = Account::where('tenant_id', $tenant->id)->findOrFail($id);
        $this->partyService->updateAccount($account, $request->validated());

        return response()->json(new AccountResource($account->fresh('primaryContact')));
    }

    /**
     * Deletes a account of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/crm/accounts/{account}',
        operationId: 'deleteCrmAccount',
        description: 'Deletes a account of the active tenant. The linked contact is kept: it is the party master of the sales documents.',
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

    /**
     * Lists the decisions of the account-to-contact consolidation, so that ambiguous accounts can be resolved by
     * relinking them (PUT /accounts/{id} with contact_id).
     */
    #[OA\Get(
        path: '/api/v1/crm/accounts/party-links',
        operationId: 'listCrmAccountPartyLinks',
        description: 'Lists the decisions taken when CRM accounts were linked to the contact master: linked, created, ambiguous (left unlinked for a person to decide) or resolved. Resolve an ambiguous account with PUT /api/v1/crm/accounts/{id} and contact_id.',
        summary: 'List account-contact link decisions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Accounts'],
        parameters: [
            new OA\Parameter(name: 'outcome', in: 'query', required: false, description: 'Filter by outcome', schema: new OA\Schema(type: 'string', enum: ['linked', 'created', 'ambiguous', 'resolved'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Link decisions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMPartyLinkLog')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function partyLinks(Request $request): JsonResponse
    {
        $request->validate(['outcome' => ['nullable', 'string', 'in:linked,created,ambiguous,resolved']]);

        $logs = PartyLinkLog::where('tenant_id', $this->tenantContext->getTenantId())
            ->when($request->filled('outcome'), fn ($query) => $query->where('outcome', $request->query('outcome')))
            ->orderByDesc('updated_at')
            ->gridPaginate(RequestedPerPage::resolve(50));

        return response()->json([
            'data' => $logs->getCollection()->map(fn (PartyLinkLog $log): array => [
                'id' => $log->id,
                'account_id' => $log->account_id,
                'outcome' => $log->outcome,
                'matched_on' => $log->matched_on,
                'contact_id' => $log->contact_id,
                'candidate_contact_ids' => $log->candidate_contact_ids ?? [],
                'note' => $log->note,
                'updated_at' => $log->updated_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $logs->currentPage(), 'total' => $logs->total()],
        ]);
    }
}
