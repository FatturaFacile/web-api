<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\ConfigureTechnicalCpqBomCommand;
use Modules\SalesBoundedContext\Application\Commands\ConfigureTechnicalCpqBomHandler;
use Modules\SalesBoundedContext\Application\Commands\ExecuteMarketingCampaignCommand;
use Modules\SalesBoundedContext\Application\Commands\ExecuteMarketingCampaignHandler;
use Modules\SalesBoundedContext\Application\Commands\ProcessPublicPrivateTenderCommand;
use Modules\SalesBoundedContext\Application\Commands\ProcessPublicPrivateTenderHandler;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\ConfigureTechnicalCpqBomRequest;
use Modules\SalesBoundedContext\Http\Requests\ExecuteMarketingCampaignRequest;
use Modules\SalesBoundedContext\Http\Requests\StorePublicPrivateTenderRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcCpqConfiguration;
use Modules\SalesBoundedContext\Models\SbcMarketingCampaign;
use Modules\SalesBoundedContext\Models\SbcPublicPrivateTender;
use OpenApi\Attributes as OA;

final class MarketingTenderCpqController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly ExecuteMarketingCampaignHandler $marketingHandler,
        private readonly ProcessPublicPrivateTenderHandler $tenderHandler,
        private readonly ConfigureTechnicalCpqBomHandler $cpqBomHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/marketing-campaigns',
        operationId: 'listSbcMarketingCampaigns',
        summary: 'List RFM marketing campaigns with insolvent/GDPR/nLPD/LCSl filtering (Mod 155)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Marketing campaigns')]
    )]
    public function campaignsIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcMarketingCampaign::class);

        return SbcRecordResource::collection(
            SbcMarketingCampaign::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/marketing-campaigns',
        operationId: 'storeSbcMarketingCampaign',
        summary: 'Execute RFM & omnichannel campaign with insolvent exclusion and GDPR/nLPD/LCSl check (Mod 155)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Marketing campaign executed')]
    )]
    public function campaignsStore(ExecuteMarketingCampaignRequest $request): JsonResponse
    {
        $this->authorize('create', SbcMarketingCampaign::class);
        $campaign = $this->marketingHandler->handle(new ExecuteMarketingCampaignCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($campaign))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/tenders',
        operationId: 'listSbcPublicPrivateTenders',
        summary: 'List simap.ch LAPub & ANAC MePA tenders (Mod 169)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Tenders list')]
    )]
    public function tendersIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcPublicPrivateTender::class);

        return SbcRecordResource::collection(
            SbcPublicPrivateTender::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/tenders',
        operationId: 'storeSbcPublicPrivateTender',
        summary: 'Submit public/private tender with SOA/eDGUE/FVOE vault and CIG/CUP tracking (Mod 169)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Tender recorded')]
    )]
    public function tendersStore(StorePublicPrivateTenderRequest $request): JsonResponse
    {
        $this->authorize('create', SbcPublicPrivateTender::class);
        $tender = $this->tenderHandler->handle(new ProcessPublicPrivateTenderCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($tender))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/tenders/{id}/convert-contract',
        operationId: 'convertSbcTenderToContract',
        summary: 'Convert awarded tender with CIG/CUP into a framework order (Mod 169)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Tender converted to framework order')]
    )]
    public function tendersConvertContract(string $id): JsonResponse
    {
        $tender = SbcPublicPrivateTender::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $tender);
        $converted = $this->tenderHandler->handle(new ProcessPublicPrivateTenderCommand($this->tenantId(), [], $id, true));

        return (new SbcRecordResource($converted))->response();
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/cpq-configurations',
        operationId: 'listSbcCpqConfigurations',
        summary: 'List technical-commercial CPQ Super-BOM configurations (Mod 230)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'CPQ configurations')]
    )]
    public function cpqConfigsIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcCpqConfiguration::class);

        return SbcRecordResource::collection(
            SbcCpqConfiguration::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/cpq-configurations',
        operationId: 'storeSbcCpqConfiguration',
        summary: 'Evaluate compatibility rules, explode Super-BOM, and compute real-time margin (Mod 230)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'CPQ configuration evaluated')]
    )]
    public function cpqConfigsStore(ConfigureTechnicalCpqBomRequest $request): JsonResponse
    {
        $this->authorize('create', SbcCpqConfiguration::class);
        $config = $this->cpqBomHandler->handle(new ConfigureTechnicalCpqBomCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($config))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
