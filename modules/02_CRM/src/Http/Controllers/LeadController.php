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
use Modules\CRM\Support\LeadNormalizer;

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

        $result = $this->leadService->captureLeadWithOutcome(new CreateLeadDTO(
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
            customFields: $request->input('custom_fields', []),
            vatNumber: $request->input('vat_number'),
            industry: $request->input('industry'),
            region: $request->input('region'),
            productLine: $request->input('product_line'),
            companySize: $request->input('company_size') !== null ? (int) $request->input('company_size') : null,
            estimatedRevenue: $request->input('estimated_revenue') !== null ? (float) $request->input('estimated_revenue') : null,
            duplicateStrategy: $request->input('duplicate_strategy'),
        ), $actorId);

        // A merged lead is the existing record updated with the new data: 200 instead of 201.
        return response()->json(
            (new LeadResource($result->lead))->resolve() + [
                'merged' => $result->merged,
                'duplicate_ids' => $result->duplicates->pluck('id')->values(),
            ],
            $result->merged ? 200 : 201
        );
    }

    public function merge(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['duplicate_id' => ['required', 'uuid']]);

        $tenant = $this->tenantContext->getTenant();
        $primary = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $duplicate = Lead::where('tenant_id', $tenant->id)->findOrFail($data['duplicate_id']);

        $merged = $this->leadService->mergeLeads($primary, $duplicate, (string) ($request->user()?->id ?? 'system'));

        return response()->json(new LeadResource($merged));
    }

    public function behavior(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'in:email_open,catalog_download,site_visit'],
            'count' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $lead = Lead::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);

        return response()->json(new LeadResource($this->leadService->recordBehavior($lead, $data['event'], (int) ($data['count'] ?? 1))));
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
            'vat_number', 'industry', 'region', 'product_line', 'company_size', 'estimated_revenue',
        ]));

        // Keep the normalised matching keys in sync with the edited data.
        $lead->update([
            'email' => LeadNormalizer::email($lead->email),
            'phone_e164' => LeadNormalizer::phoneE164($lead->phone, (string) $lead->country),
            'vat_number' => LeadNormalizer::vat($lead->vat_number),
            'company_normalized' => LeadNormalizer::company($lead->company_name),
        ]);

        $this->leadService->rescoreLead($lead);

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
            $request->query('company_name'),
            $request->query('vat_number')
        );

        return response()->json(LeadResource::collection($duplicates));
    }
}
