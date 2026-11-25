<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\CalculateGdoRebateAndAccrualCommand;
use Modules\SalesBoundedContext\Application\Commands\CalculateGdoRebateAndAccrualHandler;
use Modules\SalesBoundedContext\Application\Commands\ProcessReverseLogisticsReturnCommand;
use Modules\SalesBoundedContext\Application\Commands\ProcessReverseLogisticsReturnHandler;
use Modules\SalesBoundedContext\Application\Commands\ReconcileVanSellingRouteCommand;
use Modules\SalesBoundedContext\Application\Commands\ReconcileVanSellingRouteHandler;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\ProcessReverseLogisticsReturnRequest;
use Modules\SalesBoundedContext\Http\Requests\ReconcileVanSellingRouteRequest;
use Modules\SalesBoundedContext\Http\Requests\StoreGdoRebateAgreementRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcGdoRebateAgreement;
use Modules\SalesBoundedContext\Models\SbcReverseLogisticsReturn;
use Modules\SalesBoundedContext\Models\SbcVanSellingRoute;
use OpenApi\Attributes as OA;

final class RebateReturnVanController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly CalculateGdoRebateAndAccrualHandler $rebateHandler,
        private readonly ProcessReverseLogisticsReturnHandler $returnHandler,
        private readonly ReconcileVanSellingRouteHandler $vanHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/gdo-rebates',
        operationId: 'listSbcGdoRebateAgreements',
        summary: 'List GDO framework agreements, PFA rebates & monthly accruals (Mod 231)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'GDO rebate agreements')]
    )]
    public function rebatesIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcGdoRebateAgreement::class);

        return SbcRecordResource::collection(
            SbcGdoRebateAgreement::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/gdo-rebates',
        operationId: 'storeSbcGdoRebateAgreement',
        summary: 'Calculate tiered PFA rebate, sell-in/sell-out contributions & monthly rateo (Mod 231)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'GDO rebate calculated')]
    )]
    public function rebatesStore(StoreGdoRebateAgreementRequest $request): JsonResponse
    {
        $this->authorize('create', SbcGdoRebateAgreement::class);
        $agreement = $this->rebateHandler->handle(new CalculateGdoRebateAndAccrualCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($agreement))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/gdo-rebates/{id}/settle-credit-note',
        operationId: 'settleSbcGdoRebateCreditNote',
        summary: 'Settle accrued GDO PFA rebate with an automatic TD04 credit note (Mod 231)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'GDO rebate settled via credit note')]
    )]
    public function rebatesSettleCreditNote(string $id): JsonResponse
    {
        $agreement = SbcGdoRebateAgreement::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $agreement);
        $settled = $this->rebateHandler->handle(new CalculateGdoRebateAndAccrualCommand($this->tenantId(), [], $id, true));

        return (new SbcRecordResource($settled))->response();
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/reverse-returns',
        operationId: 'listSbcReverseLogisticsReturns',
        summary: 'List RMA reverse logistics returns, B-stock grading & Art. 26 credit notes (Mod 232)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Reverse logistics returns')]
    )]
    public function returnsIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcReverseLogisticsReturn::class);

        return SbcRecordResource::collection(
            SbcReverseLogisticsReturn::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/reverse-returns',
        operationId: 'storeSbcReverseLogisticsReturn',
        summary: 'Process RMA with advance cross-shipping, B-stock grading & Art. 26 DPR 633/72 check (Mod 232)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Reverse logistics return processed')]
    )]
    public function returnsStore(ProcessReverseLogisticsReturnRequest $request): JsonResponse
    {
        $this->authorize('create', SbcReverseLogisticsReturn::class);
        $returnRecord = $this->returnHandler->handle(new ProcessReverseLogisticsReturnCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($returnRecord))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/van-routes',
        operationId: 'listSbcVanSellingRoutes',
        summary: 'List van selling traveling warehouse routes & end-of-route reconciliations (Mod 233)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Van selling routes')]
    )]
    public function vanRoutesIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcVanSellingRoute::class);

        return SbcRecordResource::collection(
            SbcVanSellingRoute::query()
                ->where('tenant_id', $this->tenantId())
                ->latest('created_at')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/van-routes',
        operationId: 'storeSbcVanSellingRoute',
        summary: 'Reconcile van route stock, sezionale, Bluetooth ESC/POS payload & cash (Mod 233)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Van route reconciled')]
    )]
    public function vanRoutesStore(ReconcileVanSellingRouteRequest $request): JsonResponse
    {
        $this->authorize('create', SbcVanSellingRoute::class);
        $route = $this->vanHandler->handle(new ReconcileVanSellingRouteCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($route))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
