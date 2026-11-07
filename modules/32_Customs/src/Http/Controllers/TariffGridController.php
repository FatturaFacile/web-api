<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Customs\Http\Requests\SaveServiceDiscountRequest;
use Modules\Customs\Http\Requests\SaveTariffGridRequest;
use Modules\Customs\Http\Requests\SaveTariffRateRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsServiceDiscount;
use Modules\Customs\Models\CustomsTariffGrid;
use OpenApi\Attributes as OA;

/**
 * Forwarding tariffs by weight, volume, destination and service, and the discounts of the customers.
 */
final class TariffGridController extends CustomsController
{
    /**
     * List tariff grids.
     */
    #[OA\Get(path: '/api/v1/customs/tariff-grids', operationId: 'listCustomsTariffGrids', description: 'Return the tariff grids of the company.', summary: 'List tariff grids', security: [['bearerAuth' => []]], tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Grids', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsTariffGrid::class);

        return CustomsRecordResource::collection(CustomsTariffGrid::query()->orderBy('name')->paginate($this->perPage($request)));
    }

    /**
     * Create a tariff grid.
     */
    #[OA\Post(path: '/api/v1/customs/tariff-grids', operationId: 'storeCustomsTariffGrid', description: 'Create a tariff grid for a service type.', summary: 'Create tariff grid', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsTariffGridRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 201, description: 'Grid created', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function store(SaveTariffGridRequest $request): JsonResponse
    {
        $this->authorize('create', CustomsTariffGrid::class);

        return (new CustomsRecordResource(CustomsTariffGrid::query()->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Show a tariff grid.
     */
    #[OA\Get(path: '/api/v1/customs/tariff-grids/{id}', operationId: 'showCustomsTariffGrid', description: 'Return a tariff grid with its rates.', summary: 'Show tariff grid', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Grid', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function show(string $id): CustomsRecordResource
    {
        $grid = CustomsTariffGrid::query()->findOrFail($id);
        $this->authorize('view', $grid);

        return new CustomsRecordResource($grid->load('rates'));
    }

    /**
     * Change a tariff grid.
     */
    #[OA\Put(path: '/api/v1/customs/tariff-grids/{id}', operationId: 'updateCustomsTariffGrid', description: 'Change a tariff grid.', summary: 'Change tariff grid', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsTariffGridRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 200, description: 'Grid changed', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function update(SaveTariffGridRequest $request, string $id): CustomsRecordResource
    {
        $grid = CustomsTariffGrid::query()->findOrFail($id);
        $this->authorize('update', $grid);

        $grid->update($request->validated());

        return new CustomsRecordResource($grid);
    }

    /**
     * Delete a tariff grid.
     */
    #[OA\Delete(path: '/api/v1/customs/tariff-grids/{id}', operationId: 'destroyCustomsTariffGrid', description: 'Delete a tariff grid and its rates. Quotes made from it stay.', summary: 'Delete tariff grid', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 204, description: 'Grid deleted'),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $grid = CustomsTariffGrid::query()->findOrFail($id);
        $this->authorize('delete', $grid);

        $grid->delete();

        return response()->noContent();
    }

    /**
     * Add a rate.
     */
    #[OA\Post(path: '/api/v1/customs/tariff-grids/{id}/rates', operationId: 'storeCustomsTariffRate', description: 'Add a rate for a range of weight and volume and optionally a destination.', summary: 'Add tariff rate', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsTariffRateRequest')), tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId')], responses: [
        new OA\Response(response: 201, description: 'Rate added', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function storeRate(SaveTariffRateRequest $request, string $id): JsonResponse
    {
        $grid = CustomsTariffGrid::query()->findOrFail($id);
        $this->authorize('update', $grid);

        return (new CustomsRecordResource($grid->rates()->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Delete a rate.
     */
    #[OA\Delete(path: '/api/v1/customs/tariff-grids/{id}/rates/{rateId}', operationId: 'destroyCustomsTariffRate', description: 'Delete a rate of a tariff grid.', summary: 'Delete tariff rate', security: [['bearerAuth' => []]], tags: ['Customs'], parameters: [new OA\Parameter(ref: '#/components/parameters/CustomsRecordId'), new OA\Parameter(name: 'rateId', description: 'Rate identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 204, description: 'Rate deleted'),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsNotFound', response: 404),
    ])]
    public function destroyRate(string $id, string $rateId): Response
    {
        $grid = CustomsTariffGrid::query()->findOrFail($id);
        $this->authorize('update', $grid);

        $grid->rates()->whereKey($rateId)->firstOrFail()->delete();

        return response()->noContent();
    }

    /**
     * List service discounts.
     */
    #[OA\Get(path: '/api/v1/customs/service-discounts', operationId: 'listCustomsServiceDiscounts', description: 'Return the discounts of the customers by service type.', summary: 'List service discounts', security: [['bearerAuth' => []]], tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Discounts', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function discounts(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomsServiceDiscount::class);

        return CustomsRecordResource::collection(CustomsServiceDiscount::query()->paginate($this->perPage($request)));
    }

    /**
     * Set a service discount.
     */
    #[OA\Put(path: '/api/v1/customs/service-discounts', operationId: 'saveCustomsServiceDiscount', description: 'Create or change the discount of a customer on a service type.', summary: 'Set service discount', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsServiceDiscountRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Discount saved', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function saveDiscount(SaveServiceDiscountRequest $request): CustomsRecordResource
    {
        $this->authorize('create', CustomsServiceDiscount::class);

        $data = $request->validated();
        $discount = CustomsServiceDiscount::query()->updateOrCreate(
            ['contact_id' => $data['contact_id'], 'service_type' => $data['service_type']],
            ['discount_percent' => $data['discount_percent']],
        );
        $discount->wasRecentlyCreated = false;

        return new CustomsRecordResource($discount);
    }
}
