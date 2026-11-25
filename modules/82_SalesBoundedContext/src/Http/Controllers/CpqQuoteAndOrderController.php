<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\ProcessSalesOrderAndCallOffCommand;
use Modules\SalesBoundedContext\Application\Commands\ProcessSalesOrderAndCallOffHandler;
use Modules\SalesBoundedContext\Application\Commands\SignAndConvertQuoteCommand;
use Modules\SalesBoundedContext\Application\Commands\SignAndConvertQuoteHandler;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\SignCpqQuoteRequest;
use Modules\SalesBoundedContext\Http\Requests\StoreCpqQuoteRequest;
use Modules\SalesBoundedContext\Http\Requests\StoreFrameworkOrderRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcCpqQuote;
use Modules\SalesBoundedContext\Models\SbcFrameworkOrder;
use OpenApi\Attributes as OA;

final class CpqQuoteAndOrderController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly SignAndConvertQuoteHandler $quoteHandler,
        private readonly ProcessSalesOrderAndCallOffHandler $orderHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/quotes',
        operationId: 'listSbcCpqQuotes',
        summary: 'List versioned CPQ quotes with eIDAS/ZertES signature status (Mod 036)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'CPQ quotes')]
    )]
    public function quotesIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcCpqQuote::class);

        return SbcRecordResource::collection(
            SbcCpqQuote::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/quotes',
        operationId: 'storeSbcCpqQuote',
        summary: 'Create a CPQ quote with optional/bundle lines (Mod 036)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'CPQ quote created')]
    )]
    public function quotesStore(StoreCpqQuoteRequest $request): JsonResponse
    {
        $this->authorize('create', SbcCpqQuote::class);
        $quote = $this->quoteHandler->handle(new SignAndConvertQuoteCommand($this->tenantId(), 'create', $request->validated()));

        return (new SbcRecordResource($quote))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/quotes/{id}/sign',
        operationId: 'signSbcCpqQuote',
        summary: 'Sign a CPQ quote via eIDAS / ZertES with audit trail (Mod 036)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'CPQ quote signed')]
    )]
    public function quotesSign(SignCpqQuoteRequest $request, string $id): SbcRecordResource
    {
        $quote = SbcCpqQuote::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $quote);

        return new SbcRecordResource(
            $this->quoteHandler->handle(new SignAndConvertQuoteCommand($this->tenantId(), 'sign', $request->validated(), $id))
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/quotes/{id}/convert-to-order',
        operationId: 'convertSbcCpqQuoteToOrder',
        summary: '1-click convert signed CPQ quote to Sales Order (Mod 036)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Converted to sales order')]
    )]
    public function quotesConvertToOrder(string $id): SbcRecordResource
    {
        $quote = SbcCpqQuote::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $quote);

        return new SbcRecordResource(
            $this->quoteHandler->handle(new SignAndConvertQuoteCommand($this->tenantId(), 'convert_to_order', [], $id))
        );
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/orders',
        operationId: 'listSbcFrameworkOrders',
        summary: 'List Sales Orders & Framework Agreements with ATP/CTP & backorders (Mod 037)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Orders and framework agreements')]
    )]
    public function ordersIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcFrameworkOrder::class);

        return SbcRecordResource::collection(
            SbcFrameworkOrder::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/orders',
        operationId: 'storeSbcFrameworkOrder',
        summary: 'Create Framework Agreement or execute ATP/CTP Sales Order / Call-off (Mod 037)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Order processed')]
    )]
    public function ordersStore(StoreFrameworkOrderRequest $request): JsonResponse
    {
        $this->authorize('create', SbcFrameworkOrder::class);
        $order = $this->orderHandler->handle(new ProcessSalesOrderAndCallOffCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($order))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
