<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\LeadRoutingServiceInterface;
use Modules\CRM\Contracts\LeadScoringServiceInterface;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\ConvertLeadDTO;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\Exceptions\LeadAlreadyConvertedException;
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
        private readonly TenantContext $tenantContext,
        private readonly LeadScoringServiceInterface $scoring,
        private readonly LeadRoutingServiceInterface $routing,
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

        if ($request->filled('assigned_user_id')) {
            $query->where('assigned_user_id', (string) $request->query('assigned_user_id'));
        }

        if ($request->filled('source')) {
            $source = (string) $request->query('source');
            $query->whereHas('leadSource', function ($sourceQuery) use ($source): void {
                $sourceQuery->where('code', $source);
                if (Str::isUuid($source)) {
                    $sourceQuery->orWhere('id', $source);
                }
            });
        }

        if ($request->filled('min_score') && is_numeric($request->query('min_score'))) {
            $query->where('score', '>=', (int) $request->query('min_score'));
        }

        // Default order (score desc) is unchanged; sort=score|-score|created_at|-created_at overrides it.
        $sort = (string) $request->query('sort', '-score');
        match ($sort) {
            'score' => $query->orderBy('score'),
            'created_at' => $query->orderBy('created_at'),
            '-created_at' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('score'),
        };

        $leads = $query->paginate(25);

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
            customFields: $request->input('custom_fields', []),
            vatNumber: $request->input('vat_number'),
            industry: $request->input('industry'),
            employeeCount: $request->filled('employee_count') ? (int) $request->input('employee_count') : null,
            annualRevenueEstimate: $request->filled('annual_revenue_estimate') ? (float) $request->input('annual_revenue_estimate') : null,
            stateRegion: $request->input('state_region'),
            productLine: $request->input('product_line'),
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
            'vat_number', 'industry', 'employee_count', 'annual_revenue_estimate', 'state_region', 'product_line',
        ]));

        $lead = $this->scoring->rescore($lead);

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

        try {
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
                existingContactId: $request->input('existing_contact_id'),
            ), $actorId);
        } catch (LeadAlreadyConvertedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'account' => new AccountResource($result['account']),
            'contact_id' => (string) $result['contact']->id,
            'opportunity' => $result['opportunity'] ? new OpportunityResource($result['opportunity']) : null,
        ]);
    }

    /**
     * Re-computes score, rating and breakdown with the current rules and thresholds.
     */
    public function rescore(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $this->authorize('update', $lead);

        return (new LeadResource($this->scoring->rescore($lead)))->response();
    }

    /**
     * Re-runs the routing rules for the lead (assignee or admins are notified).
     */
    public function route(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $lead = Lead::where('tenant_id', $tenant->id)->findOrFail($id);
        $this->authorize('update', $lead);

        $actorId = $request->user()?->getAuthIdentifier();
        $result = $this->routing->route($lead, $actorId !== null ? (string) $actorId : null);

        return response()->json([
            'data' => (new LeadResource($lead->fresh() ?? $lead))->resolve($request),
            'routing' => [
                'assigned_user_id' => $result->assignedUserId,
                'rule_id' => $result->ruleId,
                'default_rule' => $result->usedDefaultRule,
            ],
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
