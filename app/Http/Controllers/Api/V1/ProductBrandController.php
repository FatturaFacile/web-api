<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\HandlesLookupCrud;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductBrandRequest;
use App\Models\ProductBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ProductBrandController extends Controller
{
    use HandlesLookupCrud {
        index as lookupIndex;
        show as lookupShow;
        destroy as lookupDestroy;
    }

    protected function modelClass(): string
    {
        return ProductBrand::class;
    }

    protected function label(): string
    {
        return __('api.labels.product_brand');
    }

    #[OA\Get(
        path: '/api/v1/catalog/brands',
        operationId: 'getProductBrands',
        description: 'List product brands in catalog.',
        summary: 'List product brands',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Brands retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->lookupIndex($request);
    }

    #[OA\Post(
        path: '/api/v1/catalog/brands',
        operationId: 'storeProductBrand',
        description: 'Create a new product brand in the catalog.',
        summary: 'Create product brand',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Acme Corp'),
                    new OA\Property(property: 'website', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Brand created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreProductBrandRequest $request): JsonResponse
    {
        return $this->createRecord($request->validated());
    }

    #[OA\Get(
        path: '/api/v1/catalog/brands/{id}',
        operationId: 'getProductBrand',
        description: 'Get details of a specific product brand.',
        summary: 'Get product brand',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Brand retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Brand not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->lookupShow($id);
    }

    #[OA\Put(
        path: '/api/v1/catalog/brands/{id}',
        operationId: 'updateProductBrand',
        description: 'Update product brand details.',
        summary: 'Update product brand',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'website', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Brand updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Brand not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreProductBrandRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($id, $request->validated());
    }

    #[OA\Delete(
        path: '/api/v1/catalog/brands/{id}',
        operationId: 'deleteProductBrand',
        description: 'Delete a product brand from the catalog.',
        summary: 'Delete product brand',
        security: [['bearerAuth' => []], ['tenantHeader' => []]],
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Brand deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Brand not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        return $this->lookupDestroy($id);
    }
}
