<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Controllers;

use App\Contracts\PdfGenerator;
use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\ConstructionTrades\Http\Requests\BidOperationRequest;
use Modules\ConstructionTrades\Http\Requests\SaveBidRequest;
use Modules\ConstructionTrades\Http\Requests\StorePositionRequest;
use Modules\ConstructionTrades\Http\Resources\TradesResource;
use Modules\ConstructionTrades\Models\Bid;
use Modules\ConstructionTrades\Models\BidPosition;
use Modules\ConstructionTrades\Services\TradesService;
use OpenApi\Attributes as OA;

/**
 * Bids: positions from price lists or work cycles, status, and the tender specification print.
 */
final class BidController extends Controller
{
    public function __construct(private readonly TradesService $trades) {}

    /**
     * Return the bids, newest first, optionally by status.
     */
    #[OA\Get(path: '/api/v1/construction-trades/bids', operationId: 'listTradesBids', description: 'Return the bids, newest first, optionally by status.', summary: 'List bids', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Text contained in the bid number, the title or the customer name', schema: new OA\Schema(type: 'string')),
    ], responses: [
        new OA\Response(response: 200, description: 'Bids', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Bid::class);

        return TradesResource::collection(Bid::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = $request->string('search')->trim()->toString();
                $query->where(fn ($match) => $match
                    ->whereLike('bid_number', "%{$term}%")
                    ->orWhereLike('title', "%{$term}%")
                    ->orWhereHas('contact', fn ($contact) => $contact
                        ->whereLike('company_name', "%{$term}%")
                        ->orWhereLike('first_name', "%{$term}%")
                        ->orWhereLike('last_name', "%{$term}%")));
            })
            ->latest()
            ->gridPaginate(max(1, min($request->integer('per_page', 25), 200))));
    }

    /**
     * Create a draft bid for a customer, optionally on a construction site.
     */
    #[OA\Post(path: '/api/v1/construction-trades/bids', operationId: 'storeTradesBid', description: 'Create a draft bid for a customer, optionally on a construction site.', summary: 'Create bid', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesBidRequest')), tags: ['Construction Trades'], responses: [
        new OA\Response(response: 201, description: 'Bid created', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function store(SaveBidRequest $request): JsonResponse
    {
        $this->authorize('create', Bid::class);

        return (new TradesResource($this->trades->createBid($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a bid with its positions, billings and net total.
     */
    #[OA\Get(path: '/api/v1/construction-trades/bids/{id}', operationId: 'showTradesBid', description: 'Return a bid with its positions, billings and net total.', summary: 'Show bid', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Bid', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $bid = $this->find($id, 'view');

        return (new TradesResource($bid->load(['positions', 'billings'])))->additional(['meta' => ['net_total' => $this->trades->bidNet($bid)]])->response();
    }

    /**
     * Change a draft bid.
     */
    #[OA\Put(path: '/api/v1/construction-trades/bids/{id}', operationId: 'updateTradesBid', description: 'Change a draft bid.', summary: 'Change bid', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesBidRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Bid changed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function update(SaveBidRequest $request, string $id): TradesResource
    {
        return new TradesResource($this->trades->updateBid($this->find($id, 'update'), $request->validated()));
    }

    /**
     * Submit, accept, reject or reopen a bid.
     */
    #[OA\Post(path: '/api/v1/construction-trades/bids/{id}/status', operationId: 'changeTradesBidStatus', description: 'Submit, accept, reject or reopen a bid.', summary: 'Change bid status', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesStatusRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function status(BidOperationRequest $request, string $id): TradesResource
    {
        return new TradesResource($this->trades->transition($this->find($id, 'update'), (string) $request->validated('status')));
    }

    /**
     * Add a position to a draft bid: from a price list item, from a work cycle, or by hand.
     */
    #[OA\Post(path: '/api/v1/construction-trades/bids/{id}/positions', operationId: 'storeTradesBidPosition', description: 'Add a position to a draft bid: from a price list item, from a work cycle, or by hand.', summary: 'Add bid position', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesPositionRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Position added', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storePosition(StorePositionRequest $request, string $id): JsonResponse
    {
        $bid = $this->find($id, 'update');
        $data = $request->validated();
        $position = isset($data['work_cycle_id']) ? $this->trades->addWorkCycle($bid, $data) : $this->trades->addPosition($bid, $data);

        return (new TradesResource($position))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Remove a position from a draft bid.
     */
    #[OA\Delete(path: '/api/v1/construction-trades/bids/{id}/positions/{positionId}', operationId: 'destroyTradesBidPosition', description: 'Remove a position from a draft bid.', summary: 'Remove bid position', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId'), new OA\Parameter(ref: '#/components/parameters/TradesPositionId')], responses: [
        new OA\Response(response: 204, description: 'Position removed'),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function destroyPosition(string $id, string $positionId): Response
    {
        $bid = $this->find($id, 'update');
        $this->trades->removePosition($bid, BidPosition::query()->where('bid_id', $bid->id)->findOrFail($positionId));

        return response()->noContent();
    }

    /**
     * Print the bid as a tender specification (PDF), with or without prices.
     */
    #[OA\Get(path: '/api/v1/construction-trades/bids/{id}/tender-specification', operationId: 'tradesTenderSpecification', description: 'Print the bid as a tender specification (PDF), with or without prices.', summary: 'Tender specification', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [
        new OA\Parameter(ref: '#/components/parameters/TradesId'),
        new OA\Parameter(name: 'with_prices', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: true)),
    ], responses: [
        new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function tenderSpecification(Request $request, string $id, PdfGenerator $pdf, TenantContext $tenantContext): Response
    {
        $bid = $this->find($id, 'view');
        $withPrices = $request->boolean('with_prices', true);

        $content = $pdf->render('construction-trades::tender', [
            'bid' => $bid->load(['positions', 'contact']),
            'tenant' => $tenantContext->getTenant(),
            'withPrices' => $withPrices,
            'netTotal' => $withPrices ? $this->trades->bidNet($bid) : null,
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', $bid->bid_number).'.pdf"',
        ]);
    }

    private function find(string $id, string $ability): Bid
    {
        $bid = Bid::query()->findOrFail($id);
        $this->authorize($ability, $bid);

        return $bid;
    }
}
