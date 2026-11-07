<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Customs\Enums\QuoteStatus;
use Modules\Customs\Http\Requests\GridQuoteRequest;
use Modules\Customs\Http\Requests\QuoteStatusRequest;
use Modules\Customs\Http\Requests\SpotQuoteRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsDossier;
use Modules\Customs\Models\CustomsQuote;
use Modules\Customs\Services\FreightPricingService;
use OpenApi\Attributes as OA;

/**
 * Quotes of a dossier, from the tariff grids or spot.
 */
final class QuoteController extends CustomsController
{
    public function __construct(TenantContext $tenantContext, private readonly FreightPricingService $pricing)
    {
        parent::__construct($tenantContext);
    }

    /**
     * List the quotes of a dossier.
     */
    #[OA\Get(path: '/api/v1/customs/dossiers/{id}/quotes', operationId: 'listCustomsDossierQuotes', description: 'Return the quotes of a dossier with their lines.', summary: 'List dossier quotes', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Quotes', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function index(string $id): AnonymousResourceCollection
    {
        $dossier = CustomsDossier::query()->findOrFail($id);
        $this->authorize('view', $dossier);

        return CustomsRecordResource::collection($dossier->quotes()->with('lines')->latest()->get());
    }

    /**
     * Quote from a tariff grid.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers/{id}/quotes/from-grid', operationId: 'storeCustomsGridQuote', description: 'Price a dossier from the tariff grid of its service, by weight, volume and destination, with the discount of the customer.', summary: 'Quote from tariff grid', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/CustomsGridQuoteRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 201, description: 'Quote created', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function fromGrid(GridQuoteRequest $request, string $id): JsonResponse
    {
        $dossier = CustomsDossier::query()->findOrFail($id);
        $this->authorize('update', $dossier);

        return (new CustomsRecordResource($this->pricing->quoteFromGrid($dossier, $request->validated('tariff_grid_id'))))->response()->setStatusCode(201);
    }

    /**
     * Spot quote.
     */
    #[OA\Post(path: '/api/v1/customs/dossiers/{id}/quotes/spot', operationId: 'storeCustomsSpotQuote', description: 'Create a quote with free lines.', summary: 'Spot quote', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsSpotQuoteRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 201, description: 'Quote created', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function spot(SpotQuoteRequest $request, string $id): JsonResponse
    {
        $dossier = CustomsDossier::query()->findOrFail($id);
        $this->authorize('update', $dossier);

        $data = $request->validated();

        return (new CustomsRecordResource($this->pricing->spotQuote($dossier, $data['lines'], $data['valid_until'] ?? null)))->response()->setStatusCode(201);
    }

    /**
     * Change the status of a quote.
     */
    #[OA\Post(path: '/api/v1/customs/quotes/{id}/status', operationId: 'updateCustomsQuoteStatus', description: 'Mark a quote as sent, accepted or rejected.', summary: 'Change quote status', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsQuoteStatusRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Quote changed', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function status(QuoteStatusRequest $request, string $id): CustomsRecordResource
    {
        $quote = CustomsQuote::query()->findOrFail($id);
        $this->authorize('update', $quote);

        return new CustomsRecordResource($this->pricing->setStatus($quote, QuoteStatus::from($request->validated('status'))));
    }
}
