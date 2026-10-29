<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\OpportunityServiceInterface;
use Modules\CRM\DTOs\CreateOpportunityDTO;
use Modules\CRM\DTOs\UpdateOpportunityStageDTO;
use Modules\CRM\Http\Requests\StoreOpportunityRequest;
use Modules\CRM\Http\Requests\UpdateOpportunityStageRequest;
use Modules\CRM\Http\Resources\OpportunityResource;
use Modules\CRM\Models\Opportunity;

final class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunityServiceInterface $opportunityService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = Opportunity::where('tenant_id', $tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('forecast_category')) {
            $query->where('forecast_category', $request->query('forecast_category'));
        }

        $opportunities = $query->orderByDesc('expected_revenue')->paginate(25);

        return response()->json([
            'data' => OpportunityResource::collection($opportunities),
            'meta' => [
                'current_page' => $opportunities->currentPage(),
                'total' => $opportunities->total(),
            ],
        ]);
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $opportunity = $this->opportunityService->createOpportunity(new CreateOpportunityDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            accountId: $request->input('account_id'),
            contactId: $request->input('contact_id'),
            name: $request->input('name'),
            amount: (float) $request->input('amount'),
            currency: $request->input('currency', 'EUR'),
            stageCode: $request->input('stage_code', 'discovery'),
            forecastCategory: $request->input('forecast_category', 'pipeline'),
            expectedCloseDate: $request->input('expected_close_date'),
            ownerUserId: $request->input('owner_user_id'),
            campaignId: $request->input('campaign_id'),
            leadId: $request->input('lead_id'),
            discountPercentage: (float) $request->input('discount_percentage', 0.0),
            lineItems: $request->input('line_items', []),
            tags: $request->input('tags', [])
        ), $actorId);

        return response()->json(new OpportunityResource($opportunity), 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new OpportunityResource($opp));
    }

    public function changeStage(UpdateOpportunityStageRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $updated = $this->opportunityService->changeStage($opp, new UpdateOpportunityStageDTO(
            targetStageCode: $request->input('target_stage_code'),
            amount: $request->input('amount') ? (float) $request->input('amount') : null,
            probability: $request->input('probability') ? (float) $request->input('probability') : null,
            forecastCategory: $request->input('forecast_category'),
            winLossReason: $request->input('win_loss_reason'),
            competitor: $request->input('competitor'),
            actualCloseDate: $request->input('actual_close_date')
        ), $actorId);

        return response()->json(new OpportunityResource($updated));
    }

    public function requestQuote(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $quote = $this->opportunityService->requestQuote($opp, $actorId);

        return response()->json([
            'sales_quote_id' => (string) $quote->id,
            'quote_number' => $quote->quote_number,
            'status' => $quote->status,
        ]);
    }

    public function convertToProject(Request $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? 'system');

        $result = $this->opportunityService->convertToProject($opp, $actorId);

        return response()->json([
            'opportunity_id' => (string) $result['opportunity']->id,
            'project_id' => $result['project_id'],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $opp = Opportunity::where('tenant_id', $tenant->id)->findOrFail($id);
        $opp->delete();

        return response()->json(null, 204);
    }
}
