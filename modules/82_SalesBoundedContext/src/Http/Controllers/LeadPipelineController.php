<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\CaptureAndScoreLeadCommand;
use Modules\SalesBoundedContext\Application\Commands\CaptureAndScoreLeadHandler;
use Modules\SalesBoundedContext\Application\Commands\UpdateOpportunityForecastCommand;
use Modules\SalesBoundedContext\Application\Commands\UpdateOpportunityForecastHandler;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\CaptureLeadRequest;
use Modules\SalesBoundedContext\Http\Requests\StoreOpportunityForecastRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcOmnichannelLead;
use Modules\SalesBoundedContext\Models\SbcOpportunityForecast;
use OpenApi\Attributes as OA;

final class LeadPipelineController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly CaptureAndScoreLeadHandler $leadHandler,
        private readonly UpdateOpportunityForecastHandler $opportunityHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/leads',
        operationId: 'listSbcLeads',
        summary: 'List omnichannel leads with deduplication & scoring (Mod 034)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Leads list')]
    )]
    public function leadsIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcOmnichannelLead::class);

        return SbcRecordResource::collection(
            SbcOmnichannelLead::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/leads',
        operationId: 'captureSbcLead',
        summary: 'Capture, deduplicate, score and route an omnichannel lead (Mod 034)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Lead captured')]
    )]
    public function leadsStore(CaptureLeadRequest $request): JsonResponse
    {
        $this->authorize('create', SbcOmnichannelLead::class);
        $lead = $this->leadHandler->handle(new CaptureAndScoreLeadCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($lead))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/opportunities',
        operationId: 'listSbcOpportunities',
        summary: 'List Kanban pipeline opportunities & weighted forecasts (Mod 035)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Opportunities list')]
    )]
    public function opportunitiesIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcOpportunityForecast::class);

        return SbcRecordResource::collection(
            SbcOpportunityForecast::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/opportunities',
        operationId: 'storeSbcOpportunity',
        summary: 'Create or update an opportunity forecast & quota attainment (Mod 035)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Opportunity forecast saved')]
    )]
    public function opportunitiesStore(StoreOpportunityForecastRequest $request): JsonResponse
    {
        $this->authorize('create', SbcOpportunityForecast::class);
        $opp = $this->opportunityHandler->handle(new UpdateOpportunityForecastCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($opp))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
