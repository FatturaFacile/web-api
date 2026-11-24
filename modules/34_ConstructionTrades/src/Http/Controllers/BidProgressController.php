<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\ConstructionTrades\Http\Requests\BidOperationRequest;
use Modules\ConstructionTrades\Http\Resources\TradesResource;
use Modules\ConstructionTrades\Models\Bid;
use Modules\ConstructionTrades\Models\SiteCost;
use Modules\ConstructionTrades\Services\TradesService;
use OpenApi\Attributes as OA;

/**
 * Execution of an accepted bid: measurements and settlement, partial and final billings, site costs and budget control.
 */
final class BidProgressController extends Controller
{
    public function __construct(private readonly TradesService $trades) {}

    /**
     * Record a measured quantity of a position of an accepted bid.
     */
    #[OA\Post(path: '/api/v1/construction-trades/bids/{id}/measurements', operationId: 'storeTradesMeasurement', description: 'Record a measured quantity of a position of an accepted bid.', summary: 'Record measurement', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesMeasurementRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Measurement recorded', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storeMeasurement(BidOperationRequest $request, string $id): JsonResponse
    {
        $measurement = $this->trades->recordMeasurement($this->find($id, 'update'), $request->validated(), $request->user()?->getKey());

        return (new TradesResource($measurement))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return the settlement: measured quantities per position valued at the bid prices.
     */
    #[OA\Get(path: '/api/v1/construction-trades/bids/{id}/settlement', operationId: 'showTradesSettlement', description: 'Return the settlement: measured quantities per position valued at the bid prices.', summary: 'Show settlement', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Settlement', content: new OA\JsonContent(ref: '#/components/schemas/TradesSettlementResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function settlement(string $id): JsonResponse
    {
        return response()->json(['data' => $this->trades->settlement($this->find($id, 'view'))]);
    }

    /**
     * Approve the settlement: measurements close and the final account can be issued.
     */
    #[OA\Post(path: '/api/v1/construction-trades/bids/{id}/settlement/approve', operationId: 'approveTradesSettlement', description: 'Approve the settlement: measurements close and the final account can be issued.', summary: 'Approve settlement', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Settlement approved', content: new OA\JsonContent(ref: '#/components/schemas/TradesSettlementResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function approveSettlement(string $id): JsonResponse
    {
        $bid = $this->trades->approveSettlement($this->find($id, 'bill'));

        return response()->json(['data' => $this->trades->settlement($bid)]);
    }

    /**
     * Return the partial billings and the final account of a bid.
     */
    #[OA\Get(path: '/api/v1/construction-trades/bids/{id}/billings', operationId: 'listTradesBillings', description: 'Return the partial billings and the final account of a bid.', summary: 'List billings', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Billings', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function billings(string $id): AnonymousResourceCollection
    {
        return TradesResource::collection($this->find($id, 'view')->billings()->get());
    }

    /**
     * Issue a partial billing (percentage of the bid or measured quantities, less retention) or the final account
     * (approved settlement less previous billings), as an invoice.
     */
    #[OA\Post(path: '/api/v1/construction-trades/bids/{id}/billings', operationId: 'storeTradesBilling', description: 'Issue a partial billing (percentage of the bid or measured quantities, less retention) or the final account (approved settlement less previous billings), as an invoice.', summary: 'Issue billing', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesBillingRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Billing issued', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storeBilling(BidOperationRequest $request, string $id): JsonResponse
    {
        $billing = $this->trades->bill($this->find($id, 'bill'), $request->validated());

        return (new TradesResource($billing))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return the site costs of a bid.
     */
    #[OA\Get(path: '/api/v1/construction-trades/bids/{id}/site-costs', operationId: 'listTradesSiteCosts', description: 'Return the site costs of a bid.', summary: 'List site costs', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Site costs', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function siteCosts(string $id): AnonymousResourceCollection
    {
        $bid = $this->find($id, 'view');

        return TradesResource::collection(SiteCost::query()->where('bid_id', $bid->id)->orderByDesc('cost_date')->gridPaginate(100));
    }

    /**
     * Record worker hours, material or machine costs of the site.
     */
    #[OA\Post(path: '/api/v1/construction-trades/bids/{id}/site-costs', operationId: 'storeTradesSiteCost', description: 'Record worker hours, material or machine costs of the site.', summary: 'Record site cost', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesSiteCostRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Cost recorded', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storeSiteCost(BidOperationRequest $request, string $id): JsonResponse
    {
        return (new TradesResource($this->trades->recordCost($this->find($id, 'update'), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return budget, actual costs, measured progress and variance per position.
     */
    #[OA\Get(path: '/api/v1/construction-trades/bids/{id}/budget-control', operationId: 'showTradesBudgetControl', description: 'Return budget, actual costs, measured progress and variance per position.', summary: 'Budget control', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Budget control', content: new OA\JsonContent(ref: '#/components/schemas/TradesBudgetResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function budgetControl(string $id): JsonResponse
    {
        return response()->json(['data' => $this->trades->budgetControl($this->find($id, 'view'))]);
    }

    private function find(string $id, string $ability): Bid
    {
        $bid = Bid::query()->findOrFail($id);
        $this->authorize($ability, $bid);

        return $bid;
    }
}
