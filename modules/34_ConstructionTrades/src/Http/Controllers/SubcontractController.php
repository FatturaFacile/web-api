<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ConstructionSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\ConstructionTrades\Http\Requests\SiteManagementRequest;
use Modules\ConstructionTrades\Http\Resources\TradesResource;
use Modules\ConstructionTrades\Models\Subcontract;
use Modules\ConstructionTrades\Models\Subcontractor;
use Modules\ConstructionTrades\Services\SiteManagementService;
use OpenApi\Attributes as OA;

/**
 * Subcontractors of the company, the subcontracts of a construction site and the work certified to subcontractors.
 */
final class SubcontractController extends Controller
{
    public function __construct(private readonly SiteManagementService $management) {}

    /**
     * Return the subcontractors of the company.
     */
    #[OA\Get(path: '/api/v1/construction-trades/subcontractors', operationId: 'listTradesSubcontractors', description: 'Return the subcontractors of the company, by name.', summary: 'List subcontractors', security: [['bearerAuth' => []]], tags: ['Construction Trades'], responses: [
        new OA\Response(response: 200, description: 'Subcontractors', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subcontractor::class);

        return TradesResource::collection(Subcontractor::query()->orderBy('name')->get());
    }

    /**
     * Register a subcontractor.
     */
    #[OA\Post(path: '/api/v1/construction-trades/subcontractors', operationId: 'storeTradesSubcontractor', description: 'Register a subcontractor: a firm or craftsman that works on the sites of the company, optionally linked to a contact.', summary: 'Create subcontractor', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesSubcontractorRequest')), tags: ['Construction Trades'], responses: [
        new OA\Response(response: 201, description: 'Subcontractor created', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function store(SiteManagementRequest $request): JsonResponse
    {
        $this->authorize('create', Subcontractor::class);

        return (new TradesResource(Subcontractor::query()->create(['status' => 'active', ...$request->validated()])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Change a subcontractor, or suspend it so that it takes no new subcontract.
     */
    #[OA\Put(path: '/api/v1/construction-trades/subcontractors/{id}', operationId: 'updateTradesSubcontractor', description: 'Change a subcontractor, or suspend it (status suspended) so that it takes no new subcontract; existing ones continue.', summary: 'Change subcontractor', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesSubcontractorRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Subcontractor changed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function update(SiteManagementRequest $request, string $id): TradesResource
    {
        $subcontractor = Subcontractor::query()->findOrFail($id);
        $this->authorize('update', $subcontractor);
        $subcontractor->update($request->validated());

        return new TradesResource($subcontractor);
    }

    /**
     * Return the subcontracts of a construction site.
     */
    #[OA\Get(path: '/api/v1/construction-trades/sites/{id}/subcontracts', operationId: 'listTradesSiteSubcontracts', description: 'Return the subcontracts of a construction site with their subcontractor.', summary: 'List site subcontracts', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Subcontracts', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function forSite(string $id): AnonymousResourceCollection
    {
        $site = $this->constructionSite($id, 'view');

        return TradesResource::collection(Subcontract::query()->where('construction_site_id', $site->id)->with('subcontractor')->latest()->get());
    }

    /**
     * Entrust work on a site to a subcontractor for an agreed amount.
     */
    #[OA\Post(path: '/api/v1/construction-trades/sites/{id}/subcontracts', operationId: 'storeTradesSubcontract', description: 'Entrust work on a site to an active subcontractor for an agreed amount. With a bid the certified work flows into that bid\'s site costs; the bid must belong to the same site.', summary: 'Create subcontract', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesSubcontractRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Subcontract created', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storeForSite(SiteManagementRequest $request, string $id): JsonResponse
    {
        $subcontract = $this->management->createSubcontract($this->constructionSite($id, 'update'), $request->validated());

        return (new TradesResource($subcontract->load('subcontractor')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a subcontract with its certifications and the amounts certified and left.
     */
    #[OA\Get(path: '/api/v1/construction-trades/subcontracts/{id}', operationId: 'showTradesSubcontract', description: 'Return a subcontract with its certifications and the contract, certified and remaining amounts.', summary: 'Show subcontract', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Subcontract', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $subcontract = $this->subcontract($id, 'view')->load(['subcontractor', 'certifications']);

        return response()->json(['data' => [...(new TradesResource($subcontract))->resolve(), 'totals' => $this->management->subcontractTotals($subcontract)]]);
    }

    /**
     * Certify work of the subcontractor.
     */
    #[OA\Post(path: '/api/v1/construction-trades/subcontracts/{id}/certifications', operationId: 'storeTradesSubcontractCertification', description: 'Certify work of the subcontractor on an active subcontract, never beyond the contract amount. When the subcontract belongs to a bid the amount is booked as a site cost of the bid.', summary: 'Certify subcontractor work', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesCertificationRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Work certified', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function certify(SiteManagementRequest $request, string $id): JsonResponse
    {
        $certification = $this->management->certify($this->subcontract($id, 'update'), $request->validated());

        return (new TradesResource($certification))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Complete or cancel an active subcontract.
     */
    #[OA\Post(path: '/api/v1/construction-trades/subcontracts/{id}/status', operationId: 'statusTradesSubcontract', description: 'Complete or cancel an active subcontract. Certified work stays as recorded.', summary: 'Close subcontract', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesSubcontractStatusRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Subcontract closed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function status(SiteManagementRequest $request, string $id): TradesResource
    {
        return new TradesResource($this->management->closeSubcontract($this->subcontract($id, 'update'), (string) $request->validated('status')));
    }

    private function constructionSite(string $id, string $ability): ConstructionSite
    {
        $site = ConstructionSite::query()->findOrFail($id);
        $this->authorize($ability, $site);

        return $site;
    }

    private function subcontract(string $id, string $ability): Subcontract
    {
        $subcontract = Subcontract::query()->findOrFail($id);
        $this->authorize($ability, $subcontract);

        return $subcontract;
    }
}
