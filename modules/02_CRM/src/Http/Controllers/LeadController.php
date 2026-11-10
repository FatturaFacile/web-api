<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\ConvertLeadDTO;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Http\Requests\ConvertLeadRequest;
use Modules\CRM\Http\Requests\StoreLeadRequest;
use Modules\CRM\Http\Resources\AccountResource;
use Modules\CRM\Http\Resources\ContactMasterDataResource;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Models\Lead;
use OpenApi\Attributes as OA;

final class LeadController extends Controller
{
    public function __construct(
        private readonly LeadServiceInterface $leadService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of leads of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/leads',
        operationId: 'listCrmLeads',
        description: 'Returns the paginated list of leads of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List leads',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'rating', in: 'query', required: false, description: 'Filter by rating', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of leads', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMLeadResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Lead::where('tenant_id', $tenant->id)->with('leadSource');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('rating')) {
            $query->where('rating', $request->query('rating'));
        }

        $leads = $query->orderByDesc('score')->paginate(25);

        return response()->json([
            'data' => LeadResource::collection($leads),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'total' => $leads->total(),
            ],
        ]);
    }

    /**
     * Creates a new lead in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/crm/leads',
        operationId: 'createCrmLead',
        description: 'Creates a new lead in the active tenant.',
        summary: 'Create lead',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMStoreLeadRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Lead created', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $lead = $this->leadService->captureLead(new CreateLeadDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            firstName: $request->input('first_name'),
            lastName: $request->input('last_name'),
            companyName: $request->input('company_name'),
            email: $request->input('email'),
            phone: $request->input('phone'),
            title: $request->input('title'),
            website: $request->input('website'),
            country: $request->input('country', 'IT'),
            leadSourceCode: $request->input('lead_source_code'),
            campaignId: $request->input('campaign_id'),
            assignedUserId: $request->input('assigned_user_id'),
            estimatedBudget: $request->input('estimated_budget') ? (float) $request->input('estimated_budget') : null,
            tags: $request->input('tags', []),
            customFields: $request->input('custom_fields', [])
        ), $actorId);

        return response()->json(new LeadResource($lead), 201);
    }

    /**
     * Returns a single lead of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/leads/{lead}',
        operationId: 'getCrmLead',
        description: 'Returns a single lead of the active tenant.',
        summary: 'Get lead',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'lead', in: 'path', required: true, description: 'Identifier of the lead', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new LeadResource($lead));
    }

    /**
     * Updates an existing lead of the active tenant.
     */
    #[OA\Put(
        path: '/api/v1/crm/leads/{lead}',
        operationId: 'updateCrmLead',
        description: 'Updates an existing lead of the active tenant. Also accepts the PATCH method.',
        summary: 'Update lead',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'lead', in: 'path', required: true, description: 'Identifier of the lead', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'first_name'), new OA\Property(property: 'last_name'), new OA\Property(property: 'company_name'), new OA\Property(property: 'title'), new OA\Property(property: 'email'), new OA\Property(property: 'phone'), new OA\Property(property: 'website'), new OA\Property(property: 'country'), new OA\Property(property: 'estimated_budget'), new OA\Property(property: 'tags')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);

        $lead->update($request->only([
            'first_name', 'last_name', 'company_name', 'title', 'email', 'phone', 'website', 'country', 'estimated_budget', 'tags',
        ]));

        $this->leadService->recalculateScore($lead);

        return response()->json(new LeadResource($lead->fresh()));
    }

    /**
     * Deletes a lead of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/crm/leads/{lead}',
        operationId: 'deleteCrmLead',
        description: 'Deletes a lead of the active tenant.',
        summary: 'Delete lead',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'lead', in: 'path', required: true, description: 'Identifier of the lead', schema: new OA\Schema(type: 'string', format: 'uuid')),
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
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $lead->delete();

        return response()->json(null, 204);
    }

    /**
     * Marks a lead as qualified.
     */
    #[OA\Post(
        path: '/api/v1/crm/leads/{id}/qualify',
        operationId: 'qualifyCrmLead',
        description: 'Marks a lead as qualified.',
        summary: 'Qualify lead',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the lead', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function qualify(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $qualified = $this->leadService->qualifyLead($lead, $actorId);

        return response()->json(new LeadResource($qualified));
    }

    /**
     * Marks a lead as disqualified, recording the reason.
     */
    #[OA\Post(
        path: '/api/v1/crm/leads/{id}/disqualify',
        operationId: 'disqualifyCrmLead',
        description: 'Marks a lead as disqualified, recording the reason.',
        summary: 'Disqualify lead',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the lead', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/CRMLeadResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function disqualify(Request $request, string $id): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string']]);

        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $disqualified = $this->leadService->disqualifyLead($lead, $request->input('reason'), $actorId);

        return response()->json(new LeadResource($disqualified));
    }

    /**
     * Converts a lead into an account, using an existing account or creating a new one.
     */
    #[OA\Post(
        path: '/api/v1/crm/leads/{id}/convert',
        operationId: 'convertCrmLead',
        description: 'Converts a lead into an account, using an existing account or creating a new one. The person is linked to the contact master: existing_contact_id, else the single contact with the lead email (or VAT number in custom_fields), else a new contact (contact_linked tells which). The lead consents are carried over to the contact.',
        summary: 'Convert lead',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the lead', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMConvertLeadRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'account', ref: '#/components/schemas/CRMAccountResource'), new OA\Property(property: 'contact_id', type: 'string'), new OA\Property(property: 'contact', ref: '#/components/schemas/CRMContactMasterData'), new OA\Property(property: 'contact_linked', type: 'boolean', description: 'True when an existing contact was linked instead of creating one'), new OA\Property(property: 'opportunity', ref: '#/components/schemas/CRMOpportunityResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Lead already converted', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function convert(ConvertLeadRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        if ($lead->status === 'converted') {
            return response()->json(['message' => "Lead [{$lead->id}] has already been converted."], 409);
        }

        $result = $this->leadService->convertLead($lead, new ConvertLeadDTO(
            existingAccountId: $request->input('existing_account_id'),
            newAccountName: $request->input('new_account_name'),
            accountTier: $request->input('account_tier', 'standard'),
            contactRole: $request->input('contact_role', 'decision_maker'),
            createOpportunity: $request->boolean('create_opportunity', true),
            opportunityName: $request->input('opportunity_name'),
            opportunityAmount: $request->input('opportunity_amount') ? (float) $request->input('opportunity_amount') : null,
            expectedCloseDate: $request->input('expected_close_date'),
            ownerUserId: $request->input('owner_user_id'),
            existingContactId: $request->input('existing_contact_id')
        ), $actorId);

        return response()->json([
            'account' => new AccountResource($result['account']->load('primaryContact')),
            'contact_id' => (string) $result['contact']->id,
            'contact' => new ContactMasterDataResource($result['contact']),
            'contact_linked' => $result['contact_linked'],
            'opportunity' => $result['opportunity'] ? new OpportunityResource($result['opportunity']) : null,
        ]);
    }

    /**
     * Returns the leads that match the given email, phone or company name.
     *
     * @deprecated Exact, unnormalised matching only. Use GET /api/v1/crm/duplicates?entity=leads, which normalises
     *             email and phone and also finds duplicate groups.
     */
    #[OA\Get(
        path: '/api/v1/crm/leads/duplicates',
        operationId: 'getDetectDuplicatesCrmLeadsDuplicates',
        description: 'Deprecated: use GET /api/v1/crm/duplicates?entity=leads (normalised email and phone, duplicate groups). Returns the leads that match the given email, phone or company name.',
        summary: 'Detect duplicate leads (deprecated)',
        deprecated: true,
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Leads'],
        parameters: [
            new OA\Parameter(name: 'email', in: 'query', required: false, description: 'Filter by email', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'phone', in: 'query', required: false, description: 'Filter by phone', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'company_name', in: 'query', required: false, description: 'Filter by company name', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMLeadResource'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function detectDuplicates(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $duplicates = $this->leadService->detectDuplicates(
            (string) $tenant->id,
            $request->query('email'),
            $request->query('phone'),
            $request->query('company_name')
        );

        return response()->json(LeadResource::collection($duplicates));
    }
}
