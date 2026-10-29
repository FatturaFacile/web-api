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
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Models\Lead;

final class LeadController extends Controller
{
    public function __construct(
        private readonly LeadServiceInterface $leadService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Lead::where('tenant_id', $tenant->id);

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

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new LeadResource($lead));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);

        $lead->update($request->only([
            'first_name', 'last_name', 'company_name', 'title', 'email', 'phone', 'website', 'country', 'estimated_budget', 'tags',
        ]));

        $lead->update(['score' => $this->leadService->scoreLead($lead)]);

        return response()->json(new LeadResource($lead));
    }

    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $lead->delete();

        return response()->json(null, 204);
    }

    public function qualify(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $qualified = $this->leadService->qualifyLead($lead, $actorId);

        return response()->json(new LeadResource($qualified));
    }

    public function disqualify(Request $request, string $id): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string']]);

        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $disqualified = $this->leadService->disqualifyLead($lead, $request->input('reason'), $actorId);

        return response()->json(new LeadResource($disqualified));
    }

    public function convert(ConvertLeadRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $result = $this->leadService->convertLead($lead, new ConvertLeadDTO(
            existingAccountId: $request->input('existing_account_id'),
            newAccountName: $request->input('new_account_name'),
            accountTier: $request->input('account_tier', 'standard'),
            contactRole: $request->input('contact_role', 'decision_maker'),
            createOpportunity: $request->boolean('create_opportunity', true),
            opportunityName: $request->input('opportunity_name'),
            opportunityAmount: $request->input('opportunity_amount') ? (float) $request->input('opportunity_amount') : null,
            expectedCloseDate: $request->input('expected_close_date'),
            ownerUserId: $request->input('owner_user_id')
        ), $actorId);

        return response()->json([
            'account' => new AccountResource($result['account']),
            'contact_id' => (string) $result['contact']->id,
            'opportunity' => $result['opportunity'] ? new OpportunityResource($result['opportunity']) : null,
        ]);
    }

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
