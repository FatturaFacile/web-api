<?php

declare(strict_types=1);

namespace Modules\ConstructionTrades\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\ConstructionTrades\Http\Requests\ImportPriceListRequest;
use Modules\ConstructionTrades\Http\Requests\SavePriceItemRequest;
use Modules\ConstructionTrades\Http\Requests\SavePriceListRequest;
use Modules\ConstructionTrades\Http\Resources\TradesResource;
use Modules\ConstructionTrades\Models\PriceItem;
use Modules\ConstructionTrades\Models\PriceList;
use Modules\ConstructionTrades\Services\PriceListImporter;
use OpenApi\Attributes as OA;

/**
 * Price lists: the own multilingual list of the company, and the structure for licensed catalogues imported from CSV.
 */
final class PriceListController extends Controller
{
    /**
     * Return the price lists of the company and the generic ones.
     */
    #[OA\Get(path: '/api/v1/construction-trades/price-lists', operationId: 'listTradesPriceLists', description: 'Return the price lists of the company and the generic ones.', summary: 'List price lists', security: [['bearerAuth' => []]], tags: ['Construction Trades'], responses: [
        new OA\Response(response: 200, description: 'Price lists', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordCollection')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PriceList::class);

        return TradesResource::collection(PriceList::query()->orderBy('name')->gridPaginate(max(1, min($request->integer('per_page', 25), 100))));
    }

    /**
     * Create a price list. A list from a licensed catalogue records its licence.
     */
    #[OA\Post(path: '/api/v1/construction-trades/price-lists', operationId: 'storeTradesPriceList', description: 'Create a price list. A list from a licensed catalogue records its licence.', summary: 'Create price list', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesPriceListRequest')), tags: ['Construction Trades'], responses: [
        new OA\Response(response: 201, description: 'Price list created', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function store(SavePriceListRequest $request): JsonResponse
    {
        $this->authorize('create', PriceList::class);

        $list = new PriceList;
        $list->fill($request->validated());
        $list->save();

        return (new TradesResource($list))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return a price list with its positions.
     */
    #[OA\Get(path: '/api/v1/construction-trades/price-lists/{id}', operationId: 'showTradesPriceList', description: 'Return a price list with its positions.', summary: 'Show price list', security: [['bearerAuth' => []]], tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Price list', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
    ])]
    public function show(string $id): TradesResource
    {
        $list = PriceList::query()->findOrFail($id);
        $this->authorize('view', $list);

        return new TradesResource($list->load('items'));
    }

    /**
     * Change a price list of the company; generic lists are read-only.
     */
    #[OA\Put(path: '/api/v1/construction-trades/price-lists/{id}', operationId: 'updateTradesPriceList', description: 'Change a price list of the company; generic lists are read-only.', summary: 'Change price list', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesPriceListRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Price list changed', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function update(SavePriceListRequest $request, string $id): TradesResource
    {
        $list = PriceList::query()->findOrFail($id);
        $this->authorize('update', $list);
        $list->update($request->validated());

        return new TradesResource($list);
    }

    /**
     * Add a position to a price list of the company.
     */
    #[OA\Post(path: '/api/v1/construction-trades/price-lists/{id}/items', operationId: 'storeTradesPriceItem', description: 'Add a position to a price list of the company.', summary: 'Add price position', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TradesPriceItemRequest')), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 201, description: 'Position added', content: new OA\JsonContent(ref: '#/components/schemas/TradesRecordResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function storeItem(SavePriceItemRequest $request, string $id): JsonResponse
    {
        $list = PriceList::query()->findOrFail($id);
        $this->authorize('update', $list);

        $item = new PriceItem;
        $item->fill([...$request->validated(), 'price_list_id' => $list->id]);
        $item->save();

        return (new TradesResource($item))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Import or update positions from a CSV in the neutral layout of the module. No proprietary catalogue is
     * shipped: a licensed catalogue is exported to this layout by its licence holder.
     */
    #[OA\Post(path: '/api/v1/construction-trades/price-lists/{id}/import', operationId: 'importTradesPriceList', description: 'Import or update positions from a CSV in the neutral layout of the module. No proprietary catalogue is shipped: a licensed catalogue is exported to this layout by its licence holder.', summary: 'Import price positions', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/TradesImportRequest'))), tags: ['Construction Trades'], parameters: [new OA\Parameter(ref: '#/components/parameters/TradesId')], responses: [
        new OA\Response(response: 200, description: 'Import summary', content: new OA\JsonContent(ref: '#/components/schemas/TradesImportResponse')),
        new OA\Response(ref: '#/components/responses/TradesUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TradesForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TradesNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TradesUnprocessable', response: 422),
    ])]
    public function import(ImportPriceListRequest $request, string $id, PriceListImporter $importer): JsonResponse
    {
        $list = PriceList::query()->findOrFail($id);
        $this->authorize('update', $list);

        return response()->json(['data' => $importer->import($list, (string) $request->file('file')?->getRealPath())]);
    }
}
