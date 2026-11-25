<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\CalculateFranchisingRoyaltyCommand;
use Modules\SalesBoundedContext\Application\Commands\CalculateFranchisingRoyaltyHandler;
use Modules\SalesBoundedContext\Application\Commands\ProcessCorporateWelfareSplitBillingCommand;
use Modules\SalesBoundedContext\Application\Commands\ProcessCorporateWelfareSplitBillingHandler;
use Modules\SalesBoundedContext\Application\Commands\RecordPostDeliverySurveyCommand;
use Modules\SalesBoundedContext\Application\Commands\RecordPostDeliverySurveyHandler;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\CalculateFranchisingRoyaltyRequest;
use Modules\SalesBoundedContext\Http\Requests\ProcessCorporateWelfareSplitBillingRequest;
use Modules\SalesBoundedContext\Http\Requests\RecordPostDeliverySurveyRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcCorporateWelfareConvention;
use Modules\SalesBoundedContext\Models\SbcFranchisingAffiliate;
use Modules\SalesBoundedContext\Models\SbcPostDeliverySurvey;
use OpenApi\Attributes as OA;

final class FranchiseSurveyWelfareController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly CalculateFranchisingRoyaltyHandler $franchisingHandler,
        private readonly RecordPostDeliverySurveyHandler $surveyHandler,
        private readonly ProcessCorporateWelfareSplitBillingHandler $welfareHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/franchising-affiliates',
        operationId: 'listSbcFranchisingAffiliates',
        summary: 'List franchising affiliates, sell-out royalties & store benchmarks (Mod 244)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Franchising affiliates')]
    )]
    public function affiliatesIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcFranchisingAffiliate::class);

        return SbcRecordResource::collection(
            SbcFranchisingAffiliate::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/franchising-affiliates',
        operationId: 'storeSbcFranchisingAffiliate',
        summary: 'Calculate franchising royalty & marketing fund and push central price list (Mod 244)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Franchising royalty calculated')]
    )]
    public function affiliatesStore(CalculateFranchisingRoyaltyRequest $request): JsonResponse
    {
        $this->authorize('create', SbcFranchisingAffiliate::class);
        $affiliate = $this->franchisingHandler->handle(new CalculateFranchisingRoyaltyCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($affiliate))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/surveys',
        operationId: 'listSbcPostDeliverySurveys',
        summary: 'List NPS/CSAT/CES surveys & ISO 9001 satisfaction reports (Mod 245)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Post-delivery surveys')]
    )]
    public function surveysIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcPostDeliverySurvey::class);

        return SbcRecordResource::collection(
            SbcPostDeliverySurvey::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/surveys',
        operationId: 'storeSbcPostDeliverySurvey',
        summary: 'Record post-ticket/post-delivery survey with detractor recovery & ISO 9001 index (Mod 245)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Survey recorded')]
    )]
    public function surveysStore(RecordPostDeliverySurveyRequest $request): JsonResponse
    {
        $this->authorize('create', SbcPostDeliverySurvey::class);
        $survey = $this->surveyHandler->handle(new RecordPostDeliverySurveyCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($survey))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/welfare-conventions',
        operationId: 'listSbcCorporateWelfareConventions',
        summary: 'List corporate conventions, CRAL discounts & Art. 51 TUIR / Reka split billing (Mod 358)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Corporate welfare conventions')]
    )]
    public function welfareIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcCorporateWelfareConvention::class);

        return SbcRecordResource::collection(
            SbcCorporateWelfareConvention::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/welfare-conventions',
        operationId: 'storeSbcCorporateWelfareConvention',
        summary: 'Verify corporate domain/CRAL and process entity-employee split billing (Mod 358)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Welfare split billing processed')]
    )]
    public function welfareStore(ProcessCorporateWelfareSplitBillingRequest $request): JsonResponse
    {
        $this->authorize('create', SbcCorporateWelfareConvention::class);
        $convention = $this->welfareHandler->handle(new ProcessCorporateWelfareSplitBillingCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($convention))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
