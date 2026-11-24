<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\FiscalRetail\Http\Requests\RetailPromotionRequest;
use Modules\FiscalRetail\Http\Requests\TicketItemsRequest;
use Modules\FiscalRetail\Http\Resources\RetailPromotionResource;
use Modules\FiscalRetail\Models\RetailPromotion;
use Modules\FiscalRetail\Services\TicketService;
use OpenApi\Attributes as OA;

/**
 * POS promotions (percentage, fixed amount, buy X get Y) with period, weekday and time-window rules, and the preview of
 * what a ticket would get.
 */
final class PromotionController extends Controller
{
    #[OA\Get(path: '/api/v1/fiscal-retail/pos/promotions', operationId: 'listRetailPromotions', description: 'Paginated POS promotions, by priority. Filters: type, is_active.', summary: 'List POS promotions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [
        new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['percentage', 'fixed', 'buy_x_get_y'])),
        new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Promotions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RetailPromotion'))])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RetailPromotion::class);

        return RetailPromotionResource::collection(
            RetailPromotion::query()
                ->when($request->filled('type'), static fn ($query) => $query->where('type', (string) $request->query('type')))
                ->when($request->filled('is_active'), static fn ($query) => $query->where('is_active', $request->boolean('is_active')))
                ->orderByDesc('priority')
                ->orderBy('name')
                ->gridPaginate(max(1, min(100, $request->integer('per_page', 25))))
        );
    }

    #[OA\Post(path: '/api/v1/fiscal-retail/pos/promotions', operationId: 'storeRetailPromotion', description: 'Creates a promotion. Percentage and fixed need a value; buy_x_get_y needs a product and the buy and get quantities.', summary: 'Create a POS promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RetailPromotionRequest')), tags: ['Fiscal Retail'], responses: [
        new OA\Response(response: 201, description: 'Promotion created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailPromotion')])),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function store(RetailPromotionRequest $request): JsonResponse
    {
        $this->authorize('create', RetailPromotion::class);

        $promotion = RetailPromotion::create($request->validated());

        return (new RetailPromotionResource($promotion->refresh()))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/fiscal-retail/pos/promotions/{id}', operationId: 'showRetailPromotion', description: 'A POS promotion.', summary: 'Show a POS promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Promotion', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailPromotion')])),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $id): RetailPromotionResource
    {
        $promotion = RetailPromotion::query()->findOrFail($id);
        $this->authorize('view', $promotion);

        return new RetailPromotionResource($promotion);
    }

    #[OA\Put(path: '/api/v1/fiscal-retail/pos/promotions/{id}', operationId: 'updateRetailPromotion', description: 'Changes a promotion; only the fields sent change. Tickets already sold keep the discount they got.', summary: 'Update a POS promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RetailPromotionRequest')), tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Promotion updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/RetailPromotion')])),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function update(RetailPromotionRequest $request, string $id): RetailPromotionResource
    {
        $promotion = RetailPromotion::query()->findOrFail($id);
        $this->authorize('update', $promotion);
        $promotion->update($request->validated());

        return new RetailPromotionResource($promotion->refresh());
    }

    #[OA\Delete(path: '/api/v1/fiscal-retail/pos/promotions/{id}', operationId: 'deleteRetailPromotion', description: 'Removes a promotion from the till. Tickets already sold keep their record of it.', summary: 'Delete a POS promotion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fiscal Retail'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Promotion deleted'),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $promotion = RetailPromotion::query()->findOrFail($id);
        $this->authorize('delete', $promotion);
        $promotion->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(path: '/api/v1/fiscal-retail/pos/promotions/preview', operationId: 'previewRetailPromotions', description: 'Prices the lines and applies the promotions active now without registering a sale: the lines to be sold (discount lines have a negative unit price), the promotions applied and the totals.', summary: 'Preview the promotions of a ticket', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/RetailTicketItemsRequest')), tags: ['Fiscal Retail'], responses: [
        new OA\Response(response: 200, description: 'Ticket preview', content: new OA\JsonContent(ref: '#/components/schemas/RetailTicketPreview')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function preview(TicketItemsRequest $request, TicketService $tickets): JsonResponse
    {
        $this->authorize('viewAny', RetailPromotion::class);
        $quote = $tickets->quote($request->validated('items'));

        return response()->json(['data' => [
            'items' => $quote['items'],
            'promotions' => $quote['applied'],
            'discount_total' => $quote['discount_total'],
            'total_net' => $quote['total_net'],
            'total_tax' => $quote['total_tax'],
            'total_gross' => $quote['total_gross'],
        ]]);
    }
}
