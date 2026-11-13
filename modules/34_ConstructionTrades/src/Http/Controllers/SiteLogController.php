<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ConstructionSite;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\ConstructionTrades\Http\Requests\SiteManagementRequest;
use Modules\ConstructionTrades\Http\Resources\TradesResource;
use Modules\ConstructionTrades\Models\DiaryEntry;
use Modules\ConstructionTrades\Models\SiteDocument;
use Modules\ConstructionTrades\Services\SiteManagementService;
use OpenApi\Attributes as OA;

/**
 * The daily site diary and the safety and compliance documents of a construction site, with the expiry report.
 */
final class SiteLogController extends Controller
{
    public function __construct(private readonly SiteManagementService $management) {}

    /**
     * Return the diary of a site, latest day first.
     */
    #[OA\Get(path: '/api/v1/construction-trades/sites/{id}/diary', operationId: 'listTradesSiteDiary', description: 'Return the diary of a construction site, latest day first. Paginated and filterable like every grid list.', summary: 'List site diary', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Diary entries', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function diary(string $id): AnonymousResourceCollection
    {
        $site = $this->constructionSite($id, 'view');

        return TradesResource::collection(DiaryEntry::query()->where('construction_site_id', $site->id)->orderByDesc('entry_date')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    /**
     * Write the diary of a day: weather, workers present, work performed and incidents.
     */
    #[OA\Post(path: '/api/v1/construction-trades/sites/{id}/diary', operationId: 'storeTradesSiteDiary', description: 'Write the diary of a day of the site: weather, workers present, work performed and incidents. One entry per site and day.', summary: 'Write diary entry', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesDiaryRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Entry written', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storeDiary(SiteManagementRequest $request, string $id): JsonResponse
    {
        $entry = $this->management->writeDiary($this->constructionSite($id, 'update'), $request->validated(), $request->user()?->getKey());

        return (new TradesResource($entry))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Correct a diary entry; the day itself does not change.
     */
    #[OA\Put(path: '/api/v1/construction-trades/diary/{id}', operationId: 'updateTradesSiteDiary', description: 'Correct a diary entry: weather, workers present, work performed or incidents. The day does not change.', summary: 'Change diary entry', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesDiaryRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Entry changed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function updateDiary(SiteManagementRequest $request, string $id): TradesResource
    {
        $entry = DiaryEntry::query()->findOrFail($id);
        $this->authorize('update', $entry);
        $entry->update($request->validated());

        return new TradesResource($entry);
    }

    /**
     * Return the safety and compliance documents of a site, with those of its subcontractors.
     */
    #[OA\Get(path: '/api/v1/construction-trades/sites/{id}/documents', operationId: 'listTradesSiteDocuments', description: 'Return the safety and compliance documents of a site, including those of its subcontractors, soonest expiry first.', summary: 'List site documents', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function documents(string $id): AnonymousResourceCollection
    {
        $site = $this->constructionSite($id, 'view');

        return TradesResource::collection(SiteDocument::query()->where('construction_site_id', $site->id)->with('subcontractor')->orderByRaw('expires_on is null')->orderBy('expires_on')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    /**
     * Record a safety or compliance document of the site or of a subcontractor, with its expiry date.
     */
    #[OA\Post(path: '/api/v1/construction-trades/sites/{id}/documents', operationId: 'storeTradesSiteDocument', description: 'Record a safety or compliance document of the site or of one of its subcontractors, with its expiry date. The file is uploaded in the Documents module and referenced by dms_document_id.', summary: 'Add site document', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesSiteDocumentRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Document recorded', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storeDocument(SiteManagementRequest $request, string $id): JsonResponse
    {
        $site = $this->constructionSite($id, 'update');
        $document = SiteDocument::query()->create([...$request->validated(), 'construction_site_id' => $site->id]);

        return (new TradesResource($document))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Remove a document record (the file stays in the Documents module).
     */
    #[OA\Delete(path: '/api/v1/construction-trades/site-documents/{id}', operationId: 'destroyTradesSiteDocument', description: 'Remove a site document record. The file, if any, stays in the Documents module.', summary: 'Remove site document', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 204, description: 'Document removed'),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function destroyDocument(string $id): Response
    {
        $document = SiteDocument::query()->findOrFail($id);
        $this->authorize('delete', $document);
        $document->delete();

        return response()->noContent();
    }

    /**
     * Return the documents that are expired or expire within the given days, across every site.
     */
    #[OA\Get(path: '/api/v1/construction-trades/site-documents/expiring', operationId: 'expiringTradesSiteDocuments', description: 'Return the safety and compliance documents that are expired or expire within the given number of days, across every site, soonest first. days_left is negative for an expired document.', summary: 'Expiring site documents', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(name: 'within_days', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 30))], responses: [
        new OA\Response(response: 200, description: 'Expiring documents', content: new OA\JsonContent(ref: '#/components/schemas/TradesExpiringDocumentsResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function expiring(SiteManagementRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SiteDocument::class);

        return response()->json(['data' => $this->management->expiringDocuments((int) $request->validated('within_days'))]);
    }

    private function constructionSite(string $id, string $ability): ConstructionSite
    {
        $site = ConstructionSite::query()->findOrFail($id);
        $this->authorize($ability, $site);

        return $site;
    }
}
