<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\CalculateAgentCommissionStatementCommand;
use Modules\SalesBoundedContext\Application\Commands\CalculateAgentCommissionStatementHandler;
use Modules\SalesBoundedContext\Application\Commands\ExecutePortalSelfServiceActionCommand;
use Modules\SalesBoundedContext\Application\Commands\ExecutePortalSelfServiceActionHandler;
use Modules\SalesBoundedContext\Application\Commands\IssueDdtAndDeferredInvoiceCommand;
use Modules\SalesBoundedContext\Application\Commands\IssueDdtAndDeferredInvoiceHandler;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\CalculateCommissionRequest;
use Modules\SalesBoundedContext\Http\Requests\IssueDdtInvoiceRequest;
use Modules\SalesBoundedContext\Http\Requests\PortalSelfServiceActionRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcCommissionStatement;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcPortalTransaction;
use OpenApi\Attributes as OA;

final class DdtCommissionPortalController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly IssueDdtAndDeferredInvoiceHandler $ddtInvoiceHandler,
        private readonly CalculateAgentCommissionStatementHandler $commissionHandler,
        private readonly ExecutePortalSelfServiceActionHandler $portalHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/ddt-invoices',
        operationId: 'listSbcDdtInvoices',
        summary: 'List DDT / Lieferschein, deferred summary invoices & credit/debit notes (Mod 040)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'DDT and invoices list')]
    )]
    public function ddtInvoicesIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcDdtInvoice::class);

        return SbcRecordResource::collection(
            SbcDdtInvoice::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/ddt-invoices',
        operationId: 'issueSbcDdtInvoice',
        summary: 'Issue DDT/Lieferschein, single/summary deferred invoice (TD24), drop-shipping or credit/debit note (Mod 040)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Document issued')]
    )]
    public function ddtInvoicesStore(IssueDdtInvoiceRequest $request): JsonResponse
    {
        $this->authorize('create', SbcDdtInvoice::class);
        $doc = $this->ddtInvoiceHandler->handle(new IssueDdtAndDeferredInvoiceCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($doc))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/commissions',
        operationId: 'listSbcCommissions',
        summary: 'List agent commission statements & Enasarco calculations (Mod 041)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Commission statements')]
    )]
    public function commissionsIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcCommissionStatement::class);

        return SbcRecordResource::collection(
            SbcCommissionStatement::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/commissions',
        operationId: 'calculateSbcCommission',
        summary: 'Calculate multi-tier agent commission statement & Italian Enasarco/FIRR (Mod 041)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Commission calculated')]
    )]
    public function commissionsStore(CalculateCommissionRequest $request): JsonResponse
    {
        $this->authorize('create', SbcCommissionStatement::class);
        $stmt = $this->commissionHandler->handle(new CalculateAgentCommissionStatementCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($stmt))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/portal-transactions',
        operationId: 'listSbcPortalTransactions',
        summary: 'List customer self-service portal payments, QR-Bill downloads & support tickets (Mod 042)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Portal transactions')]
    )]
    public function portalIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcPortalTransaction::class);

        return SbcRecordResource::collection(
            SbcPortalTransaction::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/portal-transactions',
        operationId: 'executeSbcPortalAction',
        summary: 'Execute portal self-service payment (Stripe/TWINT/SEPA), QR-Bill download or support request (Mod 042)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Portal action executed')]
    )]
    public function portalStore(PortalSelfServiceActionRequest $request): JsonResponse
    {
        $this->authorize('create', SbcPortalTransaction::class);
        $tx = $this->portalHandler->handle(new ExecutePortalSelfServiceActionCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($tx))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
