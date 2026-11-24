<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\MarketingAutomation\Application\Queries\GetCampaignAbTestStatsHandler;
use Modules\MarketingAutomation\Domain\Enums\CampaignStatus;
use Modules\MarketingAutomation\Exceptions\MarketingAutomationException;
use Modules\MarketingAutomation\Http\Controllers\Concerns\ResolvesMarketingContext;
use Modules\MarketingAutomation\Http\Requests\PreviewTemplateRequest;
use Modules\MarketingAutomation\Http\Requests\SaveCampaignRequest;
use Modules\MarketingAutomation\Http\Requests\SaveJourneyRequest;
use Modules\MarketingAutomation\Http\Resources\CampaignResource;
use Modules\MarketingAutomation\Http\Resources\StepLogResource;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\StepLog;
use Modules\MarketingAutomation\Services\CampaignLifecycleService;
use Modules\MarketingAutomation\Services\Journey\JourneyDesignService;
use Modules\MarketingAutomation\Services\MetricsService;
use Modules\MarketingAutomation\Services\Mjml\CampaignTemplateService;
use OpenApi\Attributes as OA;

/**
 * Campaigns and automation journeys: CRUD, visual journey persistence, lifecycle, template preview, step logs and A/B stats.
 */
final class CampaignController extends Controller
{
    use ApiResponse;
    use ResolvesMarketingContext;

    #[OA\Get(path: '/api/v1/marketing-automation/campaigns', operationId: 'marketingListCampaigns', summary: 'List campaigns', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaigns')])]
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $campaigns = Campaign::query()->with('metric')->where('tenant_id', $this->tenantId())
            ->when(is_string($status) && $status !== '', static fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->get();

        return $this->successResponse(CampaignResource::collection($campaigns));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/campaigns/{id}', operationId: 'marketingShowCampaign', summary: 'Show a campaign with its journey and metrics', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaign')])]
    public function show(string $id): JsonResponse
    {
        $campaign = Campaign::query()->with(['steps', 'metric'])->where('tenant_id', $this->tenantId())->findOrFail($id);

        return $this->successResponse(new CampaignResource($campaign));
    }

    #[OA\Post(path: '/api/v1/marketing-automation/campaigns', operationId: 'marketingStoreCampaign', summary: 'Create a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 201, description: 'Campaign created')])]
    public function store(SaveCampaignRequest $request, MetricsService $metrics): JsonResponse
    {
        $campaign = Campaign::query()->create([...$request->validated(), 'tenant_id' => $this->tenantId(), 'status' => CampaignStatus::Draft->value, 'created_by' => $this->userId($request)]);
        $metrics->recompute($campaign);

        return $this->successResponse(new CampaignResource($campaign->load('metric')), 'Campaign created', 201);
    }

    #[OA\Put(path: '/api/v1/marketing-automation/campaigns/{id}', operationId: 'marketingUpdateCampaign', summary: 'Update a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaign updated')])]
    public function update(SaveCampaignRequest $request, string $id, MetricsService $metrics): JsonResponse
    {
        $campaign = Campaign::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        if (in_array($campaign->status, ['completed', 'archived'], true)) {
            throw MarketingAutomationException::invalidTransition((string) $campaign->status, 'edit');
        }

        $campaign->fill([...$request->validated(), 'updated_by' => $this->userId($request)])->save();
        $metrics->recompute($campaign);

        return $this->successResponse(new CampaignResource($campaign->load('metric')));
    }

    #[OA\Delete(path: '/api/v1/marketing-automation/campaigns/{id}', operationId: 'marketingDeleteCampaign', summary: 'Delete a draft campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaign deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $campaign = Campaign::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        if ($campaign->status !== CampaignStatus::Draft->value) {
            throw MarketingAutomationException::invalidTransition((string) $campaign->status, 'delete');
        }
        $campaign->delete();

        return $this->successResponse(null, 'Campaign deleted');
    }

    #[OA\Put(path: '/api/v1/marketing-automation/campaigns/{id}/journey', operationId: 'marketingSaveJourney', summary: 'Replace the journey steps (nodes and edges) of a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Journey saved')])]
    public function saveJourney(SaveJourneyRequest $request, string $id, JourneyDesignService $design): JsonResponse
    {
        $campaign = Campaign::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $design->replace($campaign, (array) $request->validated('steps'));

        return $this->successResponse(new CampaignResource($campaign->load(['steps', 'metric'])));
    }

    #[OA\Post(path: '/api/v1/marketing-automation/campaigns/{id}/activate', operationId: 'marketingActivateCampaign', summary: 'Activate (or resume) a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaign active')])]
    public function activate(string $id, CampaignLifecycleService $lifecycle): JsonResponse
    {
        return $this->move($id, $lifecycle, CampaignStatus::Active);
    }

    #[OA\Post(path: '/api/v1/marketing-automation/campaigns/{id}/schedule', operationId: 'marketingScheduleCampaign', summary: 'Schedule a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaign scheduled')])]
    public function schedule(string $id, CampaignLifecycleService $lifecycle): JsonResponse
    {
        return $this->move($id, $lifecycle, CampaignStatus::Scheduled);
    }

    #[OA\Post(path: '/api/v1/marketing-automation/campaigns/{id}/pause', operationId: 'marketingPauseCampaign', summary: 'Pause a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaign paused')])]
    public function pause(string $id, CampaignLifecycleService $lifecycle): JsonResponse
    {
        return $this->move($id, $lifecycle, CampaignStatus::Paused);
    }

    #[OA\Post(path: '/api/v1/marketing-automation/campaigns/{id}/archive', operationId: 'marketingArchiveCampaign', summary: 'Archive a campaign', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Campaign archived')])]
    public function archive(string $id, CampaignLifecycleService $lifecycle): JsonResponse
    {
        return $this->move($id, $lifecycle, CampaignStatus::Archived);
    }

    #[OA\Post(path: '/api/v1/marketing-automation/template-preview', operationId: 'marketingPreviewTemplate', summary: 'Compile a template to HTML for one locale and A/B variant', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Rendered subject, MJML and HTML')])]
    public function previewTemplate(PreviewTemplateRequest $request, CampaignTemplateService $templates): JsonResponse
    {
        try {
            $rendered = $templates->render((array) $request->validated('template'), (string) $request->validated('locale'), $request->validated('variant'));
        } catch (InvalidArgumentException $e) {
            throw MarketingAutomationException::validation('invalid_mjml', ['detail' => $e->getMessage()]);
        }

        return $this->successResponse($rendered);
    }

    #[OA\Get(path: '/api/v1/marketing-automation/campaigns/{id}/logs', operationId: 'marketingCampaignStepLogs', summary: 'Step execution logs of a journey', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'Step logs')])]
    public function logs(string $id): JsonResponse
    {
        $campaign = Campaign::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $logs = StepLog::query()->where('campaign_id', $campaign->id)->orderByDesc('executed_at')->limit(200)->get();

        return $this->successResponse(StepLogResource::collection($logs));
    }

    #[OA\Get(path: '/api/v1/marketing-automation/campaigns/{id}/ab-stats', operationId: 'marketingCampaignAbStats', summary: 'A/B test statistics', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Marketing Automation'], responses: [new OA\Response(response: 200, description: 'A/B stats')])]
    public function abStats(string $id, GetCampaignAbTestStatsHandler $handler): JsonResponse
    {
        $campaign = Campaign::query()->where('tenant_id', $this->tenantId())->findOrFail($id);

        return $this->successResponse($handler->handle($campaign));
    }

    private function move(string $id, CampaignLifecycleService $lifecycle, CampaignStatus $target): JsonResponse
    {
        $campaign = Campaign::query()->where('tenant_id', $this->tenantId())->findOrFail($id);

        return $this->successResponse(new CampaignResource($lifecycle->transition($campaign, $target)->load(['steps', 'metric'])));
    }
}
