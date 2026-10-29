<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\CampaignServiceInterface;
use Modules\CRM\Http\Requests\StoreCampaignRequest;
use Modules\CRM\Http\Resources\CampaignResource;
use Modules\CRM\Models\Campaign;

final class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignServiceInterface $campaignService,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $campaigns = Campaign::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => CampaignResource::collection($campaigns),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? 'system');

        $campaign = $this->campaignService->createCampaign(array_merge(
            $request->validated(),
            ['tenant_id' => (string) $tenant->id]
        ), $actorId);

        return response()->json(new CampaignResource($campaign), 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $campaign = Campaign::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(new CampaignResource($campaign));
    }

    public function roi(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $campaign = Campaign::where('tenant_id', $tenant->id)->findOrFail($id);

        $roiData = $this->campaignService->calculateROI($campaign);

        return response()->json($roiData);
    }
}
